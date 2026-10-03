<?php
/**
 * Wires the plugin's parts to WordPress, Polylang and WPGraphQL.
 *
 * @package GQ\PolylangGraphQL
 */

namespace GQ\PolylangGraphQL;

defined( 'ABSPATH' ) || exit;

/**
 * Boots the plugin: the Polylang context for GraphQL requests is chosen while
 * Polylang initializes (plugins_loaded), so it is hooked at once; everything
 * else waits until WPGraphQL and Polylang are both known to be active.
 */
final class Plugin {

	/**
	 * Hooks the plugin. Called once, when the plugin file loads.
	 */
	public static function boot(): void {
		Context::hook();
		add_action( 'plugins_loaded', array( self::class, 'init' ), 20 );
	}

	/**
	 * Registers the schema and resolver hooks, or explains why it can't.
	 */
	public static function init(): void {
		$missing = self::missing_dependencies();
		if ( ! empty( $missing ) ) {
			self::notice(
				sprintf(
					/* translators: %s: plugin names */
					__( 'GQ Polylang for WPGraphQL needs %s to be active.', 'gq-polylang-graphql' ),
					implode( ' ' . __( 'and', 'gq-polylang-graphql' ) . ' ', $missing )
				)
			);
			return;
		}

		// valu-digital/wp-graphql-polylang registers the same types.
		if ( defined( 'WPGRAPHQL_POLYLANG' ) || class_exists( '\WPGraphQL\Extensions\Polylang\Loader', false ) ) {
			self::notice( __( 'GQ Polylang for WPGraphQL replaces WP GraphQL Polylang: deactivate one of them.', 'gq-polylang-graphql' ) );
			return;
		}

		add_action( 'graphql_register_types', array( Schema::class, 'register' ) );
		Content::hook();
		Front_Pages::hook();
		Menus::hook();
	}

	/**
	 * The required plugins that aren't active.
	 *
	 * @return string[]
	 */
	private static function missing_dependencies(): array {
		$missing = array();
		if ( ! function_exists( 'register_graphql_field' ) ) {
			$missing[] = 'WPGraphQL';
		}
		if ( ! function_exists( 'pll_languages_list' ) ) {
			$missing[] = 'Polylang';
		}
		return $missing;
	}

	/**
	 * Shows an admin notice to those who manage plugins.
	 *
	 * @param string $message The notice.
	 */
	private static function notice( string $message ): void {
		add_action(
			'admin_notices',
			static function () use ( $message ) {
				if ( current_user_can( 'activate_plugins' ) ) {
					printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
				}
			}
		);
	}
}
