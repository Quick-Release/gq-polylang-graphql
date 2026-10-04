#!/usr/bin/env bash
# Uses installed .test-site core/plugins, but a NEW database and temporary
# WordPress root inside DDEV. Never resets or modifies the existing database.
# Requires the dependencies installed by run.sh; does not download or upgrade.
set -euo pipefail
cd "$(dirname "$0")/../.."
ddev start

# Unique names; CREATE (without IF NOT EXISTS) prevents reusing another DB.
database="gq_batch_$(date +%s)_${RANDOM}"
site="/tmp/$database"
mode="${1:-regression}"
ddev mysql -e "CREATE DATABASE \`$database\`;"
cleanup() {
  ddev exec rm -rf "$site"
  ddev mysql -e "REVOKE ALL ON \`$database\`.* FROM 'db'@'%'; DROP DATABASE \`$database\`;"
}
trap cleanup EXIT
ddev mysql -e "GRANT ALL ON \`$database\`.* TO 'db'@'%';"

ddev exec bash -s -- "$site" "$database" "$mode" <<'SH'
set -euo pipefail
site=$1
database=$2
mode=$3
core=/var/www/html/.test-site
mkdir -p "$site/wp-content/plugins"
# Share only installed code, not wp-config.php or wp-content writable state.
for entry in "$core"/*.php "$core/wp-admin" "$core/wp-includes"; do
  [[ $(basename "$entry") == wp-config.php ]] || ln -s "$entry" "$site/"
done
for plugin in wp-graphql polylang gq-polylang-graphql; do
  test -f "$core/wp-content/plugins/$plugin/$plugin.php"
  ln -s "$core/wp-content/plugins/$plugin" "$site/wp-content/plugins/$plugin"
done
wp() { command wp --path="$site" "$@"; }
wp config create --dbname="$database" --dbuser=db --dbpass=db --dbhost=db
wp core install --url=http://translation-batching.test --title='Translation batching' \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email
wp plugin activate wp-graphql polylang gq-polylang-graphql

wp eval-file /var/www/html/tests/integration/translation-batching-fixture.php
for sample in 1 2 3; do
  wp eval-file /var/www/html/tests/integration/translation-batching.php "$mode"
done
wp eval-file /var/www/html/tests/integration/translation-batching.php "$mode" admin
SH
