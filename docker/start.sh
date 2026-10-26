#!/bin/sh
# Starts the BookTrack demo: a fresh SQLite library with the demo data, then Apache.
set -e
cd /var/www/html
export PORT="${PORT:-10000}"
echo "Listen ${PORT}" > /etc/apache2/ports.conf
php tests/make_sqlite.php /var/lib/booktrack/booktrack.sqlite
chown -R www-data:www-data /var/lib/booktrack
exec apache2-foreground
