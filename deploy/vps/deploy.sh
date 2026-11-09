#!/bin/sh
# Builds and (re)starts FileNest on the VPS. Run from the repo root on the server: sh deploy/vps/deploy.sh
# First run: creates .env with random secrets, adds the site to Caddy, and replaces the seeded admin's
# default password with the one whose hash is in deploy/vps/admin-password.hash.
set -eu
cd "$(dirname "$0")/../.."

compose() { sudo docker compose --env-file .env -f deploy/vps/docker-compose.yml "$@"; }
rand() { head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c "$1"; }

first_run=no
if [ ! -f .env ]; then
    first_run=yes
    umask 077
    sed -e "s|^APP_KEY=.*|APP_KEY=base64:$(head -c 32 /dev/urandom | base64)|" \
        -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$(rand 32)|" \
        -e "s|^DB_ROOT_PASSWORD=.*|DB_ROOT_PASSWORD=$(rand 32)|" \
        deploy/vps/env.example > .env
    echo "Created .env with fresh secrets."
fi
# The AMC connector's token (added to an older .env too); the app finds the connector by its service name.
if ! grep -q '^AMC_TOKEN=' .env; then
    printf '\nAMC_URL=http://amc:8080\nAMC_TOKEN=%s\n' "$(rand 40)" >> .env
    echo "Added the AMC connector's token to .env."
elif grep -q '^AMC_TOKEN=$' .env; then
    sed -i "s|^AMC_TOKEN=$|AMC_TOKEN=$(rand 40)|" .env
    echo "Filled the AMC connector's token in .env."
fi
mkdir -p public/downloads
# The web and app containers read this folder as another user, so it must stay world-readable.
chmod 755 public public/downloads
find public/downloads -maxdepth 1 -type f -exec chmod 644 {} +

# Database backup before every update (last 20 kept in ~/examnest-backups). The uploaded files stay in
# the examnest_storage volume, which an update never touches. A failed backup stops the deploy.
if sudo docker ps --format '{{.Names}}' | grep -qx examnest-db; then
    backups="$HOME/examnest-backups"
    stamp="$(date +%Y%m%d_%H%M%S)"
    mkdir -p "$backups"
    sudo docker exec examnest-db sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines "$MYSQL_DATABASE"' \
        | gzip > "$backups/db_$stamp.sql.gz"
    gzip -dc "$backups/db_$stamp.sql.gz" | grep -q 'CREATE TABLE' \
        || { echo "Database backup failed; stopping before the update." >&2; rm -f "$backups/db_$stamp.sql.gz"; exit 1; }
    ls -1t "$backups"/db_*.sql.gz | tail -n +21 | xargs -r rm -f
    echo "Backed up to $backups (db_$stamp.sql.gz)."
fi

compose build --pull
compose up -d --remove-orphans

echo "Waiting for the app to finish migrating..."
tries=0
until sudo docker exec examnest-app test -f storage/app/.seeded 2>/dev/null; do
    tries=$((tries + 1))
    [ "$tries" -ge 120 ] && { echo "App did not finish starting; see: sudo docker logs examnest-app" >&2; exit 1; }
    sleep 2
done
# Workers keep old code in memory; restart them on every deploy.
compose restart queue scheduler >/dev/null

# First install only: the seeded admin gets the password whose bcrypt hash is in deploy/vps/admin-password.hash
# (the repository is public, so only the hash is kept there; the password itself was handed over privately).
if [ ! -f "$HOME/.examnest-admin-set" ]; then
    hash="$(tr -d '\n' < deploy/vps/admin-password.hash)"
    sudo docker exec -e NEW_HASH="$hash" examnest-app php artisan tinker --execute='Illuminate\Support\Facades\DB::table("users")->where("email","admin@exams.test")->update(["password" => getenv("NEW_HASH")]);' >/dev/null
    touch "$HOME/.examnest-admin-set"
    echo "Admin password set from deploy/vps/admin-password.hash"
fi

sh deploy/vps/caddy-site.sh

echo "PHP $(sudo docker exec examnest-app php -r 'echo PHP_VERSION;')"
compose ps
