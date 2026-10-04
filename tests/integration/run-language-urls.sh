#!/usr/bin/env bash
# Isolated temporary WordPress/database; never changes the existing test DB.
set -euo pipefail
cd "$(dirname "$0")/../.."
ddev start
database="gq_urls_$(date +%s)_${RANDOM}"
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
polylang=polylang
[[ -d "$core/wp-content/plugins/polylang" ]] || polylang=polylang-pro
for plugin in wp-graphql "$polylang" gq-polylang-graphql; do
  test -d "$core/wp-content/plugins/$plugin"
  ln -s "$core/wp-content/plugins/$plugin" "$site/wp-content/plugins/$plugin"
done
wp() { command wp --path="$site" "$@"; }
wp config create --dbname="$database" --dbuser=db --dbpass=db --dbhost=db
wp core install --url=http://urls.test --title='Language URLs' \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email
wp plugin activate wp-graphql "$polylang" gq-polylang-graphql
wp eval-file /var/www/html/tests/integration/fixture.php
for base in root subdirectory; do
  for mode in directory domain subdomain query; do
    wp eval-file /var/www/html/tests/integration/language-urls.php configure "$mode" "$base"
    wp rewrite flush
    wp eval-file /var/www/html/tests/integration/language-urls.php assert "$mode" "$base"
    if [[ $mode == query || $mode == directory ]]; then
      wp eval 'PLL()->options["hide_default"] = false; PLL()->options->save();'
      wp eval-file /var/www/html/tests/integration/language-urls.php ambiguous "$mode" "$base"
    fi
  done
done
SH
