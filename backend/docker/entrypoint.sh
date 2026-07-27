#!/bin/sh
# Point d'entrée du conteneur backend : prépare l'appli puis lance PHP-FPM.
set -e
cd /var/www/html

# 1. Clés JWT — générées une seule fois puis persistées (volume config/jwt).
if [ ! -f config/jwt/private.pem ]; then
  echo "→ Génération des clés JWT..."
  php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction
fi

# 2. Attente que la base MariaDB soit prête à accepter les connexions.
echo "→ Attente de la base de données..."
until php bin/console dbal:run-sql "SELECT 1" >/dev/null 2>&1; do
  sleep 2
done

# 3. Migrations Doctrine (idempotent : ne fait rien si déjà à jour).
echo "→ Migrations Doctrine..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# 4. Cache de production.
php bin/console cache:clear --no-warmup
php bin/console cache:warmup

echo "→ Backend prêt."
exec "$@"
