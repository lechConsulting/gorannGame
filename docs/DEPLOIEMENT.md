# Déploiement — gorann.games.lechat-consulting.fr

Guide pas-à-pas pour héberger le jeu sur le VPS `51.210.111.212`, **sans toucher
aux autres sites** déjà en place.

---

## 1. Comment ça marche (le principe)

Le serveur a déjà un **nginx** qui écoute sur les ports 80/443 et aiguille chaque
domaine vers le bon site. On ne le remplace pas : on lui **ajoute juste une entrée**.

```
Internet ─▶ nginx de l'hôte (déjà là) ─┬─▶ tes autres sites      (inchangés)
   :443                                 └─▶ gorann.games...        ─▶ 127.0.0.1:8090
                                                                        │
                                             Stack Docker "gorann" ◀────┘
                                             ├─ web    (nginx + SPA React)
                                             ├─ php    (Symfony 7.4)
                                             ├─ mercure(temps réel SSE)
                                             └─ db     (MariaDB dédiée, isolée)
```

- Toute l'appli tourne dans **Docker**, dans sa bulle. Sa base MariaDB est **séparée**.
- Elle n'écoute que sur `127.0.0.1:8090` → **invisible depuis Internet**.
- Le nginx de l'hôte fait le pont depuis le domaine, et gère le certificat HTTPS.
- Tout est servi sur **un seul domaine** → pas de problème de CORS.

---

## 2. Prérequis sur le serveur

Connecte-toi : `ssh ubuntu@51.210.111.212 -p 3675` (port SSH **non standard**), puis
vérifie ce qui est déjà présent :

```bash
docker --version && docker compose version   # Docker installé ?
nginx -v                                       # nginx (l'aiguilleur) présent ?
certbot --version                              # outil de certificat HTTPS ?
```

- **nginx** est très probablement déjà là (il sert tes autres sites). On le réutilise.
- Si **Docker** manque, installe-le (n'impacte pas tes sites) :
  ```bash
  curl -fsSL https://get.docker.com | sh
  ```
- Si **certbot** manque : `sudo apt install certbot python3-certbot-nginx`.

---

## 3. Récupérer le code

> ℹ️ **Sur ce serveur, le dépôt est déjà cloné dans `/var/www/gorann`** (et non
> `/opt/gorann`). Les commandes ci-dessous utilisent `/var/www/gorann` ; adapte
> le chemin si tu réinstalles ailleurs.

```bash
cd /var/www                 # emplacement utilisé sur ce serveur
git clone git@github.com:lechConsulting/gorannGame.git gorann
cd gorann
```

> Le serveur doit pouvoir cloner en SSH (clé de déploiement sur le compte GitHub),
> sinon utilise l'URL HTTPS : `https://github.com/lechConsulting/gorannGame.git`.

---

## 4. Configurer les secrets

```bash
cp deploy/.env.example deploy/.env
nano deploy/.env
```

Remplis chaque valeur `REMPLACER_...`. Pour générer des secrets solides :

```bash
openssl rand -hex 16   # pour APP_SECRET
openssl rand -hex 32   # pour JWT_PASSPHRASE et MERCURE_JWT_SECRET
```

Choisis aussi des mots de passe forts pour `DB_PASSWORD` et `DB_ROOT_PASSWORD`.
Le fichier `deploy/.env` **n'est jamais commité** (il est dans `.gitignore`).

---

## 5. Lancer la stack Docker

```bash
docker compose --env-file deploy/.env -f compose.prod.yaml up -d --build
```

Le premier lancement build les images (quelques minutes). Au démarrage, le backend
génère automatiquement les **clés JWT**, attend la base, puis applique les **migrations**.

Vérifie que tout tourne :

```bash
docker compose --env-file deploy/.env -f compose.prod.yaml ps
docker compose --env-file deploy/.env -f compose.prod.yaml logs -f php   # Ctrl-C pour quitter
```

Test local (avant même le HTTPS) :

```bash
curl -I http://127.0.0.1:8090        # doit répondre 200 (la SPA)
```

---

## 6. Charger les cartes (seed) — une seule fois

Les 89 cartes + 13 héros sont dans `backend/data/deck.json`. On les importe en base :

```bash
docker compose --env-file deploy/.env -f compose.prod.yaml exec php php bin/console app:seed
```

> **Le premier compte créé via l'inscription devient automatiquement admin.**
> Inscris-toi en premier sur le site, puis accède au back-office (bouton 🔧).

---

## 7. Brancher le domaine (nginx de l'hôte)

On ajoute le vhost, **sans toucher** aux autres :

```bash
sudo cp deploy/host-nginx-gorann.conf /etc/nginx/sites-available/gorann
sudo ln -s /etc/nginx/sites-available/gorann /etc/nginx/sites-enabled/gorann
sudo nginx -t                 # vérifie qu'il n'y a pas d'erreur
sudo systemctl reload nginx   # recharge SANS couper les sites en cours
```

> Si ton serveur n'utilise pas `sites-available/sites-enabled` (ex. tout dans
> `/etc/nginx/conf.d/`), copie plutôt le fichier là : `sudo cp deploy/host-nginx-gorann.conf /etc/nginx/conf.d/gorann.conf`.

À ce stade, `http://gorann.games.lechat-consulting.fr` doit afficher le jeu.

---

## 8. Activer le HTTPS

```bash
sudo certbot --nginx -d gorann.games.lechat-consulting.fr
```

Certbot obtient le certificat Let's Encrypt, modifie le vhost pour ajouter le 443
et la redirection HTTP→HTTPS, et programme le renouvellement automatique.

✅ **C'est en ligne : https://gorann.games.lechat-consulting.fr**

---

## 9. Mettre à jour l'appli (après un `git push`)

**En une seule commande** (recommandé) :

```bash
/var/www/gorann/deploy/update.sh
```

Le script enchaîne : `git pull` → rebuild & redémarrage Docker → attente que le
backend soit prêt (les migrations s'appliquent au démarrage) → promotion du
super-admin (idempotente). Il s'arrête avec un message clair si `deploy/.env`
manque ou si le backend ne répond pas.

<details>
<summary>Équivalent manuel</summary>

```bash
cd /var/www/gorann
git pull
docker compose --env-file deploy/.env -f compose.prod.yaml up -d --build
# une fois le conteneur php prêt (migrations appliquées) :
docker compose --env-file deploy/.env -f compose.prod.yaml exec php php bin/console app:promote-super-admin
```

Les migrations éventuelles s'appliquent toutes seules au redémarrage du conteneur `php`.
</details>

> 💾 S'il y a des migrations, pense à sauvegarder la base avant (voir §10).

---

## 10. Sauvegarde de la base

```bash
docker compose --env-file deploy/.env -f compose.prod.yaml exec db \
  sh -c 'mariadb-dump -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' > gorann-backup-$(date +%F).sql
```

Restauration :

```bash
docker compose --env-file deploy/.env -f compose.prod.yaml exec -T db \
  sh -c 'mariadb -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' < gorann-backup-AAAA-MM-JJ.sql
```

---

## Dépannage

| Symptôme | Piste |
|---|---|
| `curl 127.0.0.1:8090` ne répond pas | `docker compose ... logs web php` — un conteneur a crashé |
| Le port 8090 est déjà pris | change `8090` dans `compose.prod.yaml` **et** dans `deploy/host-nginx-gorann.conf` |
| 502 Bad Gateway sur le domaine | la stack Docker n'est pas up, ou mauvais port dans le vhost hôte |
| Erreur 500 à l'API | `docker compose ... logs php` ; souvent une variable manquante dans `deploy/.env` |
| Temps réel qui ne marche pas | vérifie le conteneur `mercure` (`logs mercure`) et que `/.well-known/mercure` passe |
| « JWT keys not found » | supprime le volume `gorann_jwt_keys` et relance pour régénérer les clés |

---

## Rappel sécurité

- Les secrets réels vivent dans `deploy/.env` (jamais commité).
- La base MariaDB du jeu est **dédiée** et n'expose aucun port public.
- Seul `127.0.0.1:8090` est exposé, uniquement en local — le reste passe par le nginx de l'hôte.
