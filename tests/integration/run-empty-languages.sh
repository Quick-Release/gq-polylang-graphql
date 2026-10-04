#!/usr/bin/env bash
# Uses installed .test-site core/plugins, but a NEW database and temporary
# WordPress root inside DDEV. Never resets or modifies the existing database.
# Requires the dependencies installed by run.sh; does not download or upgrade.
set -euo pipefail
cd "$(dirname "$0")/../.."
ddev start

# Unique names; CREATE (without IF NOT EXISTS) prevents reusing another DB.
database="gq_empty_$(date +%s)_${RANDOM}"
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
# Share only installed code, not wp-config.php or wp-content writable state.
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
wp core install --url=http://empty-languages.test --title='Empty languages' \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email
wp plugin activate wp-graphql "$polylang" gq-polylang-graphql

# Separate WP loads mirror independent requests and avoid schema/model caches.
assertions=/var/www/html/tests/integration/empty-languages.php
wp eval-file "$assertions" before-configuration
wp eval '
$result = PLL()->model->languages->add( array( "slug" => "en", "locale" => "en_US", "name" => "English" ) );
if ( is_wp_error( $result ) ) { WP_CLI::error( $result->get_error_message() ); }
PLL()->model->clean_languages_cache();
PLL()->options["default_lang"] = "en";
PLL()->options->save();
'
wp eval-file "$assertions" configured
wp eval '
$lang = PLL()->model->get_language( "en" );
if ( ! $lang || ! PLL()->model->languages->delete( $lang->term_id ) ) { WP_CLI::error( "Could not delete the last language." ); }
PLL()->options->save();
'
wp eval-file "$assertions" after-last-language-removed
SH
