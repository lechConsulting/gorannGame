# LOTR Deck Builder — Backend (Symfony 7.4 LTS)

API du jeu de cartes en ligne (multi-jeux, multijoueur jusqu'à 5).

## Stack

- **Symfony 7.4 LTS** / PHP 8.4
- **Doctrine ORM** + Migrations
- **Sécurité** : JWT (LexikJWTAuthenticationBundle), stateless
- **CORS** : nelmio/cors-bundle (autorise localhost par défaut)
- **BDD** : MariaDB 11.6.2 (base `test_lotr`) — repli SQLite possible, voir en bas

## Démarrage

```bash
cd backend
composer install
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:seed          # admin + jeu LOTR + 6 cartes de test
symfony server:start -d           # ou: php -S 127.0.0.1:8000 -t public
```

Compte admin de démonstration : `admin@lotr.local` / `admin1234` (pseudo Gandalf).

## Modèle de données

| Entité | Rôle |
|--------|------|
| `User` | Joueur. Rôles `ROLE_JOUEUR` (par défaut) et `ROLE_ADMIN` (admin = aussi joueur). Le 1ᵉʳ compte créé est admin. |
| `Game` | Définition d'un jeu de cartes (l'outil est **multi-jeux**). |
| `Card` | Carte d'un `Game` : type, coût, PV, texte, quantité, image, `attributes` (JSON libre). |
| `GameSession` | Une **partie** jouée : statut, code de table, `state` (JSON complet, sauvegarde/reprise), dates. |
| `GameSessionPlayer` | Participation + résultat (score, rang, vainqueur). **Source des classements.** |

## Endpoints

| Méthode | Route | Accès | Description |
|--------|-------|-------|-------------|
| POST | `/api/register` | public | Inscription (email, pseudo, password) |
| POST | `/api/login` | public | Login → renvoie un JWT |
| GET | `/api/me` | joueur | Profil + stats |
| GET | `/api/games` | public | Liste des jeux publiés |
| GET | `/api/games/{slug}` | public | Détail d'un jeu + ses cartes |
| GET | `/api/leaderboard` | public | Classements `daily` + `global` (option `?game=slug`) |

Auth : envoyer `Authorization: Bearer <token>` sur les routes protégées.

## Classements

Calculés à la volée depuis `GameSessionPlayer` (parties **terminées** uniquement) :
`GameSessionPlayerRepository::dailyLeaderboard()`, `globalLeaderboard()`,
`countGamesForUser()`, `statsForUser()`. Tri : victoires ↓, score total ↓, parties ↓.

## Base de données (MariaDB)

La BDD active est **MariaDB**, base `test_lotr`. Le `DATABASE_URL` avec identifiants
est dans `backend/.env.local` (non committé) :

```dotenv
DATABASE_URL="mysql://claude:********@127.0.0.1:3306/test_lotr?serverVersion=11.6.2-MariaDB&charset=utf8mb4"
```

> Le user MySQL `claude` n'a pas le droit `CREATE DATABASE` global : il n'a les
> pleins droits que sur les bases nommées `test` / `test_*`, d'où le nom `test_lotr`.

Repli SQLite (aucun serveur) : dé-commenter la ligne SQLite dans `.env` et retirer
l'override de `.env.local`, puis rejouer migrations + seed.

## Reste à faire

- Endpoints admin (CRUD jeux/cartes) + upload d'images de cartes
- Moteur de partie (tours, achat, pouvoir, PV, défausse) une fois les règles fournies
- Lobby (créer/rejoindre une table) + temps réel via **Mercure** (SSE) pour les 5 joueurs
- Écriture des résultats en fin de partie (score/rang/vainqueur) → alimente les classements
```
