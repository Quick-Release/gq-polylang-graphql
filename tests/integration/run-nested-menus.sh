#!/usr/bin/env bash
# Isolated WordPress/database: never resets or modifies the existing test DB.
set -euo pipefail
cd "$(dirname "$0")/../.."
ddev start
database="gq_menus_$(date +%s)_${RANDOM}"
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
mkdir -p "$site/wp-content/plugins" "$site/wp-content/mu-plugins"
for entry in "$core"/*.php "$core/wp-admin" "$core/wp-includes"; do
  [[ $(basename "$entry") == wp-config.php ]] || ln -s "$entry" "$site/"
done
for plugin in wp-graphql polylang gq-polylang-graphql; do
  test -f "$core/wp-content/plugins/$plugin/$plugin.php"
  ln -s "$core/wp-content/plugins/$plugin" "$site/wp-content/plugins/$plugin"
done
printf '%s\n' '<?php add_action( "after_setup_theme", static function () { register_nav_menus( array( "primary" => "Primary", "secondary" => "Secondary" ) ); } );' > "$site/wp-content/mu-plugins/menu-locations.php"
wp() { command wp --path="$site" "$@"; }
wp config create --dbname="$database" --dbuser=db --dbpass=db --dbhost=db
wp core install --url=http://nested-menus.test --title='Nested menus' \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email
wp plugin activate wp-graphql polylang gq-polylang-graphql
wp eval-file /var/www/html/tests/integration/fixture.php
wp eval-file /var/www/html/tests/integration/hierarchical-menus-fixture.php
wp user create reader reader@example.test --role=subscriber --user_pass=reader
# Independent requests ensure authorization/model caches cannot leak identities.
wp eval-file /var/www/html/tests/integration/nested-menus.php anonymous
wp eval-file /var/www/html/tests/integration/nested-menus.php administrator
wp eval-file /var/www/html/tests/integration/nested-menus.php subscriber
wp eval-file /var/www/html/tests/integration/nested-menus.php anonymous
SH
