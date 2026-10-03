#!/usr/bin/env sh
# Installs WordPress inside the dev stack and activates the plugin.
# Build the admin app first: (cd ../wordpress-plugin/jcore-pakkaus && pnpm install && pnpm build)
set -e
cd "$(dirname "$0")"
wp() { docker compose run --rm wpcli wp "$@"; }

until docker compose exec -T db mariadb-admin ping -uwordpress -pwordpress --silent 2>/dev/null; do sleep 2; done

# The browser uses localhost:8080; mu-plugins/dev-urls.php points the optimizer at http://wordpress.
wp core is-installed 2>/dev/null || wp core install --url=http://localhost:8080--title="JCORE Pakkaus Dev" \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
wp plugin activate jcore-pakkaus
echo "Ready. Import a video with: docker compose run --rm wpcli wp media import /samples/<file>"
