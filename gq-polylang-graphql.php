<?php
/**
 * Plugin Name:       GQ Polylang for WPGraphQL
 * Plugin URI:        https://github.com/Quick-Release/gq-polylang-graphql
 * Description:       Exposes Polylang's languages and translations in WPGraphQL, for headless multilingual sites.
 * Version:           0.1.2
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  wp-graphql
 * Author:            GETQUICK
 * Author URI:        https://getquick.io
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       gq-polylang-graphql
 *
 * @package GQ\PolylangGraphQL
 */

namespace GQ\PolylangGraphQL;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.1.2';

require_once __DIR__ . '/src/class-languages.php';
require_once __DIR__ . '/src/class-context.php';
require_once __DIR__ . '/src/class-schema.php';
require_once __DIR__ . '/src/class-content.php';
require_once __DIR__ . '/src/class-front-pages.php';
require_once __DIR__ . '/src/class-menus.php';
require_once __DIR__ . '/src/class-plugin.php';

Plugin::boot();
