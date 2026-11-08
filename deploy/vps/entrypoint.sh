#!/bin/sh
# Prepares the application, then runs the given command. Only the php-fpm container migrates and seeds;
# the queue worker and scheduler wait for it (storage/app/.seeded).
set -e
cd /var/www/html

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    storage/app/public storage/app/private storage/app/filemanager

if [ "$1" = "php-fpm" ]; then
    echo "Waiting for the database..."
    tries=0
    until php -r 'try { new PDO("mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT")?:3306).";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); } catch (Throwable $e) { exit(1); }'; do
        tries=$((tries + 1))
        [ "$tries" -ge 60 ] && { echo "Database did not come up." >&2; exit 1; }
        sleep 2
    done

    php artisan migrate --force --no-interaction
    # The seeder writes the default settings and the first admin; it only runs on an empty install.
    if [ ! -f storage/app/.seeded ]; then
        php artisan db:seed --force --no-interaction
        # A public site: self sign-up off until the admin turns it on (Admin > Settings).
        php artisan tinker --execute='App\Models\Setting::setMany(["registration_enabled" => "0"]);' >/dev/null || true
        touch storage/app/.seeded
    fi
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
    chown -R www-data:www-data storage bootstrap/cache
else
    tries=0
    until [ -f storage/app/.seeded ]; do
        tries=$((tries + 1))
        [ "$tries" -ge 150 ] && { echo "The app container did not finish preparing." >&2; exit 1; }
        sleep 2
    done
fi

exec docker-php-entrypoint "$@"
