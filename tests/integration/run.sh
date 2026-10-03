#!/usr/bin/env bash
# Runs the integration tests against a disposable DDEV WordPress (.test-site),
# reset on every run: WPGraphQL and Polylang from WordPress.org, this plugin
# linked in, the bilingual fixture (fixture.php), then graphql.test.mjs over
# HTTP, so Polylang runs as it does for a real GraphQL request.
#
#   tests/integration/run.sh
#   POLYLANG_DIR=../polylang-pro tests/integration/run.sh   # Polylang Pro instead
#   WPGRAPHQL_VERSION=2.23.1 tests/integration/run.sh       # a WPGraphQL version
set -euo pipefail
cd "$(dirname "$0")/../.."

site=http://gq-polylang-graphql-testsite.ddev.site
plugins=.test-site/wp-content/plugins
mkdir -p "$plugins" .test-site/wp-content/mu-plugins
ln -sfn ../../.. "$plugins/gq-polylang-graphql"
# A classic menu location for the menu tests (the default theme has none).
cat > .test-site/wp-content/mu-plugins/test-menu-location.php <<'PHP'
<?php
add_action( 'after_setup_theme', static function () {
	register_nav_menus( array( 'primary' => 'Primary' ) );
} );
PHP

ddev start
wp() { ddev wp --path=.test-site "$@"; }
if ! ddev exec test -f .test-site/wp-includes/version.php 2>/dev/null; then
  wp core download --skip-content
fi
if ! ddev exec test -f .test-site/wp-config.php 2>/dev/null; then
  wp config create --dbname=db --dbuser=db --dbpass=db --dbhost=db
fi
wp config set GRAPHQL_DEBUG true --raw
wp db reset --yes
wp core install --url="$site" --title='Site de teste' --admin_user=admin \
  --admin_password=admin --admin_email=admin@example.test --skip-email
wp theme install twentytwentyfive --activate

wp plugin install wp-graphql ${WPGRAPHQL_VERSION:+--version="$WPGRAPHQL_VERSION"} --force
if [[ -n "${POLYLANG_DIR:-}" ]]; then
  rm -rf "$plugins/polylang"
  rsync -a --delete --exclude .git --exclude node_modules "$POLYLANG_DIR/" "$plugins/polylang-pro/"
  polylang=polylang-pro
else
  rm -rf "$plugins/polylang-pro"
  wp plugin install polylang --force
  polylang=polylang
fi
wp plugin activate wp-graphql "$polylang" gq-polylang-graphql
wp rewrite structure '/%postname%/'
wp eval-file tests/integration/fixture.php
wp rewrite flush --hard

GRAPHQL_URL="$site/graphql" node --test tests/integration/graphql.test.mjs
