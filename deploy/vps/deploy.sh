#!/bin/sh
# Builds and (re)starts FileNest on the VPS. Run from the repo root on the server: sh deploy/vps/deploy.sh
# First run: creates .env with random secrets, adds the site to Caddy, and replaces the seeded admin's
# default password with a random one saved to ~/filenest-admin-password.txt (readable only by this user).
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
mkdir -p public/downloads
# The web and app containers read this folder as another user, so it must stay world-readable.
chmod 755 public public/downloads
find public/downloads -maxdepth 1 -type f -exec chmod 644 {} +

# Database backup before every update (last 20 kept in ~/filenest-backups). The uploaded files stay in
# the filenest_storage volume, which an update never touches. A failed backup stops the deploy.
if sudo docker ps --format '{{.Names}}' | grep -qx filenest-db; then
    backups="$HOME/filenest-backups"
    stamp="$(date +%Y%m%d_%H%M%S)"
    mkdir -p "$backups"
    sudo docker exec filenest-db sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines "$MYSQL_DATABASE"' \
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
until sudo docker exec filenest-app test -f storage/app/.seeded 2>/dev/null; do
    tries=$((tries + 1))
    [ "$tries" -ge 120 ] && { echo "App did not finish starting; see: sudo docker logs filenest-app" >&2; exit 1; }
    sleep 2
done
# Workers keep old code in memory; restart them on every deploy.
compose restart queue scheduler >/dev/null

if [ "$first_run" = yes ] || [ ! -f "$HOME/filenest-admin-password.txt" ]; then
    pw="$(rand 16)"
    sudo docker exec -e NEW_PW="$pw" filenest-app php artisan tinker --execute='App\Models\User::where("email","admin@filenest.test")->update(["password" => Illuminate\Support\Facades\Hash::make(getenv("NEW_PW"))]);' >/dev/null
    (umask 077; printf 'email: admin@filenest.test\npassword: %s\n' "$pw" > "$HOME/filenest-admin-password.txt")
    echo "Admin password saved to ~/filenest-admin-password.txt"
fi

sh deploy/vps/caddy-site.sh

echo "PHP $(sudo docker exec filenest-app php -r 'echo PHP_VERSION;')"
compose ps
