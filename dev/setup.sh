#!/usr/bin/env sh
# Installs WordPress inside the dev stack and activates the plugin.
set -e
cd "$(dirname "$0")"
wp() { docker compose run --rm wpcli wp "$@"; }

until docker compose exec -T db mariadb-admin ping -uwordpress -pwordpress --silent 2>/dev/null; do sleep 2; done

# The site URL uses the in-network hostname so the optimizer container can reach it.
wp core is-installed 2>/dev/null || wp core install --url=http://wordpress --title="Video Optimizer Dev" \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
wp plugin activate video-optimizer
echo "Ready. Import a video with: docker compose run --rm wpcli wp media import /samples/<file>"
