<?php
/**
 * Verifies installed versions and dependency headers before activating plugins.
 *
 * @package GQ\PolylangGraphQL
 */

// WP-CLI does not guarantee these admin helpers are already loaded.
require_once ABSPATH . 'wp-admin/includes/plugin.php';

/**
 * Checks a version constraint with a diagnostic on failure.
 *
 * @param string $actual Installed version.
 * @param string $minimum Required version.
 * @param string $label Dependency label.
 */
function gq_stack_minimum( string $actual, string $minimum, string $label ): void {
	if ( '' !== $minimum && version_compare( $actual, $minimum, '<' ) ) {
		WP_CLI::error( "$label requires $minimum; installed $actual." );
	}
}

/**
 * Verifies that an explicit version request was honored.
 *
 * @param string $actual Installed version.
 * @param string $requested Requested version or latest.
 * @param string $label Component label.
 */
function gq_stack_exact( string $actual, string $requested, string $label ): void {
	if ( '' !== $requested && 'latest' !== $requested && $actual !== $requested ) {
		WP_CLI::error( "$label requested $requested; installed $actual." );
	}
}

global $wp_version, $required_php_version;
gq_stack_minimum( PHP_VERSION, $required_php_version, 'WordPress PHP' );
gq_stack_minimum( $wp_version, '6.5', 'Plugin WordPress' );
gq_stack_minimum( PHP_VERSION, '7.4', 'Plugin PHP' );
gq_stack_exact( $wp_version, $args[1], 'WordPress' );
if ( ! empty( $args[2] ) && ! preg_match( '/^' . preg_quote( $args[2], '/' ) . '\./', PHP_VERSION ) ) {
	WP_CLI::error( 'Requested PHP minor does not match ' . PHP_VERSION );
}
foreach ( array(
	'wp-graphql' => array( '2.0.0', $args[3] ),
	$args[0]     => array( '3.7', $args[4] ),
) as $gq_slug => $gq_versions ) {
	$gq_plugins = get_plugins( '/' . $gq_slug );
	if ( 1 !== count( $gq_plugins ) ) {
		WP_CLI::error( 'Expected one plugin entry point: ' . $gq_slug );
	}
	$gq_header = reset( $gq_plugins );
	if ( empty( $gq_header['Version'] ) || empty( $gq_header['RequiresWP'] ) || empty( $gq_header['RequiresPHP'] ) ) {
		WP_CLI::error( 'Missing dependency headers: ' . $gq_slug );
	}
	// Directory readmes can impose a higher minimum than the PHP header.
	$gq_readme_path = WP_PLUGIN_DIR . '/' . $gq_slug . '/readme.txt';
	if ( is_readable( $gq_readme_path ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local distribution metadata.
		$gq_readme = file_get_contents( $gq_readme_path );
		foreach ( array(
			'Requires at least' => $wp_version,
			'Requires PHP'      => PHP_VERSION,
		) as $gq_requirement => $gq_actual ) {
			if ( preg_match( '/^' . preg_quote( $gq_requirement, '/' ) . ':\s*([0-9.]+)/mi', $gq_readme, $gq_match ) ) {
				gq_stack_minimum( $gq_actual, $gq_match[1], $gq_slug . ' readme ' . $gq_requirement );
			}
		}
	}
	gq_stack_minimum( $wp_version, $gq_header['RequiresWP'], $gq_slug . ' WordPress' );
	gq_stack_minimum( PHP_VERSION, $gq_header['RequiresPHP'], $gq_slug . ' PHP' );
	gq_stack_minimum( $gq_header['Version'], $gq_versions[0], $gq_slug );
	gq_stack_exact( $gq_header['Version'], $gq_versions[1], $gq_slug );
	WP_CLI::log( $gq_slug . ': ' . $gq_header['Version'] . ' (WP >= ' . $gq_header['RequiresWP'] . ', PHP >= ' . $gq_header['RequiresPHP'] . ')' );
}
WP_CLI::success( 'Compatible dependency headers: WordPress ' . $wp_version . ', PHP ' . PHP_VERSION );
