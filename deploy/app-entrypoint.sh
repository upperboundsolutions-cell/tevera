#!/bin/sh
set -eu
mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage bootstrap/cache
php artisan config:cache
if [ "$1" = "apache2-foreground" ]; then
	exec "$@"
fi
exec gosu www-data "$@"
