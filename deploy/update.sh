#!/bin/sh
# ---------------------------------------------------------------------------
# Mise à jour de l'application en PRODUCTION — en une seule commande.
#
# À lancer sur le VPS, depuis n'importe où (le script se recale tout seul) :
#     /opt/gorann/deploy/update.sh
#
# Étapes : git pull → (re)build & redémarrage Docker → attente que le backend
# soit prêt (les migrations s'appliquent au démarrage) → promotion du
# super-admin (idempotente).
#
# 💾 Astuce : pour une sauvegarde de la base AVANT la mise à jour, lance
#    d'abord la commande de dump de DEPLOIEMENT.md §10 (recommandé s'il y a
#    des migrations).
# ---------------------------------------------------------------------------
set -eu

# Se placer à la racine du dépôt (ce script est dans deploy/).
cd "$(dirname "$0")/.."

COMPOSE="docker compose --env-file deploy/.env -f compose.prod.yaml"

# Garde-fou : le fichier de secrets doit exister (voir DEPLOIEMENT.md §4).
if [ ! -f deploy/.env ]; then
  echo "!! deploy/.env introuvable. Copie deploy/.env.example et remplis-le d'abord." >&2
  exit 1
fi

echo "==> 1/4  Récupération du code (git pull)"
git pull --ff-only

echo "==> 2/4  (Re)build et redémarrage des conteneurs"
$COMPOSE up -d --build

echo "==> 3/4  Attente que le backend soit prêt (migrations en cours…)"
# L'entrypoint génère les clés, attend la base puis applique les migrations
# avant de lancer PHP-FPM. On patiente jusqu'à ~2 min qu'une commande réponde.
i=0
until $COMPOSE exec -T php php bin/console dbal:run-sql "SELECT 1" >/dev/null 2>&1; do
  i=$((i + 1))
  if [ "$i" -ge 60 ]; then
    echo "!! Backend toujours pas prêt après 2 min. Regarde les logs :" >&2
    echo "   $COMPOSE logs --tail=50 php" >&2
    exit 1
  fi
  sleep 2
done

echo "==> 4/4  Promotion du super-admin (idempotent)"
$COMPOSE exec -T php php bin/console app:promote-super-admin

# Domaine (pour le message final) : lu depuis deploy/.env, avec un repli.
APP_DOMAIN=$(sed -n 's/^APP_DOMAIN=//p' deploy/.env | head -n1)
APP_DOMAIN=${APP_DOMAIN:-gorann.games.lechat-consulting.fr}

echo ""
echo "✅ Mise à jour terminée."
echo "   Vérif : curl -I https://$APP_DOMAIN"
