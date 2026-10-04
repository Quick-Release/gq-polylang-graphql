#!/usr/bin/env bash
# Destructive setup ONLY for a fresh or previously marked disposable DDEV site.
# Never run this against an existing unmarked .test-site; use isolated runners.
# Versions default to latest. POLYLANG_DIR can provide a local Pro distribution.
set -euo pipefail
cd "$(dirname "$0")/../.."

marker=.test-site/.gq-disposable
if [[ -f .test-site/wp-config.php && ! -f "$marker" ]]; then
  echo 'Refusing to reset an existing unmarked .test-site. Use a fresh checkout/project.' >&2
  exit 1
fi
if [[ -n "${POLYLANG_DIR:-}" && -n "${POLYLANG_VERSION:-}" ]]; then
  echo 'Choose POLYLANG_DIR or POLYLANG_VERSION, not both.' >&2
  exit 1
fi

# Allow independent disposable DDEV projects (e.g. local matrix verification).
project=$(awk '/^name:/ {print $2}' .ddev/config.yaml)
site="http://${project}.ddev.site"
plugins=.test-site/wp-content/plugins
mkdir -p "$plugins" .test-site/wp-content/mu-plugins
touch "$marker"
ln -sfn ../../.. "$plugins/gq-polylang-graphql"
cat > .test-site/wp-content/mu-plugins/test-menu-location.php <<'PHP'
<?php
add_action( 'after_setup_theme', static function () {
	register_nav_menus( array( 'primary' => 'Primary' ) );
} );
PHP

if [[ -n "${PHP_VERSION:-}" ]]; then
  ddev config --php-version="$PHP_VERSION"
fi
ddev start
wp() { ddev wp --path=.test-site "$@"; }
# Remove old core directories on disposable reruns: --force leaves obsolete files.
ddev exec rm -rf .test-site/wp-admin .test-site/wp-includes
wp core download --version="${WORDPRESS_VERSION:-latest}" --skip-content --force
wp core verify-checksums
if ! ddev exec test -f .test-site/wp-config.php 2>/dev/null; then
  wp config create --dbname=db --dbuser=db --dbpass=db --dbhost=db
fi
# The marker is not permission to target another site's configured database.
if [[ "$(wp config get DB_NAME)" != db || "$(wp config get DB_HOST)" != db ]]; then
  echo 'Refusing to reset a database other than this DDEV project database.' >&2
  exit 1
fi
# Pinning downloads is insufficient if an HTTP request launches an auto-update.
wp config set AUTOMATIC_UPDATER_DISABLED true --raw
wp config set WP_AUTO_UPDATE_CORE false --raw
wp config set DISABLE_WP_CRON true --raw
wp config set GRAPHQL_DEBUG true --raw
wp db reset --yes
wp core install --url="$site" --title='Site de teste' --admin_user=admin \
  --admin_password=admin --admin_email=admin@example.test --skip-email
# Twenty Twenty-Five needs WP 6.7; this classic theme supports both stacks.
wp theme install twentytwentyone --version=2.6 --activate --force

wp plugin install wp-graphql --version="${WPGRAPHQL_VERSION:-latest}" --force
if [[ -n "${POLYLANG_DIR:-}" ]]; then
  rm -rf "$plugins/polylang"
  rsync -a --delete --exclude .git --exclude node_modules "$POLYLANG_DIR/" "$plugins/polylang-pro/"
  polylang=polylang-pro
else
  rm -rf "$plugins/polylang-pro"
  wp plugin install polylang --version="${POLYLANG_VERSION:-latest}" --force
  polylang=polylang
fi
wp eval-file tests/integration/check-stack.php "$polylang" \
  "${WORDPRESS_VERSION:-latest}" "${PHP_VERSION:-}" \
  "${WPGRAPHQL_VERSION:-latest}" "${POLYLANG_VERSION:-latest}"
wp plugin activate wp-graphql "$polylang" gq-polylang-graphql
wp rewrite structure '/%postname%/'
wp eval-file tests/integration/fixture.php
wp rewrite flush --hard

GRAPHQL_URL="$site/graphql" node --test tests/integration/graphql.test.mjs
# Reuse the installed versions, but each suite owns a NEW temporary database.
for suite in nested-menus empty-languages language-urls language-enums translation-batching; do
  "tests/integration/run-$suite.sh"
done
# Detect any stack drift during HTTP requests or the isolated suites.
wp eval-file tests/integration/check-stack.php "$polylang" \
  "${WORDPRESS_VERSION:-latest}" "${PHP_VERSION:-}" \
  "${WPGRAPHQL_VERSION:-latest}" "${POLYLANG_VERSION:-latest}"
