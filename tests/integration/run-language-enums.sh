#!/usr/bin/env bash
# Uses a new temporary WordPress/database; never modifies the existing test DB.
set -euo pipefail
cd "$(dirname "$0")/../.."
ddev start
database="gq_enums_$(date +%s)_${RANDOM}"
site="/tmp/$database"
ddev mysql -e "CREATE DATABASE \`$database\`;"
cleanup() {
  ddev exec rm -rf "$site"
  ddev mysql -e "REVOKE ALL ON \`$database\`.* FROM 'db'@'%'; DROP DATABASE \`$database\`;"
}
trap cleanup EXIT
ddev mysql -e "GRANT ALL ON \`$database\`.* TO 'db'@'%';"
ddev exec bash -s -- "$site" "$database" <<'SH'
set -euo pipefail
site=$1
database=$2
core=/var/www/html/.test-site
mkdir -p "$site/wp-content/plugins"
for entry in "$core"/*.php "$core/wp-admin" "$core/wp-includes"; do
  [[ $(basename "$entry") == wp-config.php ]] || ln -s "$entry" "$site/"
done
for plugin in wp-graphql polylang gq-polylang-graphql; do
  test -f "$core/wp-content/plugins/$plugin/$plugin.php"
  ln -s "$core/wp-content/plugins/$plugin" "$site/wp-content/plugins/$plugin"
done
wp() { command wp --path="$site" "$@"; }
wp config create --dbname="$database" --dbuser=db --dbpass=db --dbhost=db
wp core install --url=http://language-enums.test --title='Language enums' \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email
wp plugin activate wp-graphql polylang gq-polylang-graphql
for stage in basic collisions reorder reserved-default; do
  wp eval-file /var/www/html/tests/integration/language-enums-fixture.php "$stage"
  wp eval-file /var/www/html/tests/integration/language-enums.php "$stage"
done
SH
