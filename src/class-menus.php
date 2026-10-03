<?php
/**
 * Menus per language.
 *
 * @package GQ\PolylangGraphQL
 */

namespace GQ\PolylangGraphQL;

use WPGraphQL\Data\Connection\MenuItemConnectionResolver;

defined( 'ABSPATH' ) || exit;

/**
 * Polylang assigns a menu to each theme location per language, in its
 * `nav_menus` option (theme → location → language → menu ID). WPGraphQL
 * reads locations from the `nav_menu_locations` theme mod, which holds the
 * default language's menus. `menuItems(where: { location, language })`
 * returns the items of that language's menu at the location; with
 * `language` alone, those of every location's menu in that language.
 */
final class Menus {

	/**
	 * Hooks the menu item connection.
	 */
	public static function hook(): void {
		add_filter( 'graphql_connection_query_args', array( self::class, 'query_args' ), 10, 3 );
		add_filter( 'graphql_pre_model_data_is_private', array( self::class, 'is_private' ), 10, 3 );
	}

	/**
	 * Makes public the items of a menu Polylang assigned to a location in
	 * any language. WPGraphQL shows the public only items of menus in the
	 * `nav_menu_locations` theme mod, which holds the default language's.
	 *
	 * @param bool|null $is_private Another filter's decision, or null.
	 * @param string    $model_name The model's name.
	 * @param mixed     $data       The model's data.
	 * @return bool|null
	 */
	public static function is_private( $is_private, $model_name, $data ) {
		if ( null !== $is_private || 'MenuItemObject' !== $model_name || ! $data instanceof \WP_Post ) {
			return $is_private;
		}
		$menus = wp_get_object_terms( $data->ID, 'nav_menu', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $menus ) || empty( $menus ) ) {
			return $is_private;
		}
		return in_array( (int) $menus[0], self::assigned_menu_ids(), true ) ? false : $is_private;
	}

	/**
	 * Every menu ID Polylang assigned to a location of the current theme, in
	 * any language.
	 *
	 * @return int[]
	 */
	private static function assigned_menu_ids(): array {
		$ids = array();
		foreach ( self::theme_locations() as $by_language ) {
			foreach ( is_array( $by_language ) ? $by_language : array() as $menu_id ) {
				$ids[] = (int) $menu_id;
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Polylang's menu assignments for the current theme: location → language
	 * slug → menu ID.
	 *
	 * @return array<string,mixed>
	 */
	private static function theme_locations(): array {
		$options   = PLL()->options;
		$nav_menus = isset( $options['nav_menus'] ) && is_array( $options['nav_menus'] ) ? $options['nav_menus'] : array();
		$theme     = (string) get_option( 'stylesheet' );
		return isset( $nav_menus[ $theme ] ) && is_array( $nav_menus[ $theme ] ) ? $nav_menus[ $theme ] : array();
	}

	/**
	 * Adds `language` to the menu item connections' where-arguments.
	 */
	public static function register_fields(): void {
		add_filter(
			'graphql_input_fields',
			static function ( $fields, $type_name ) {
				if ( is_array( $fields ) && substr( (string) $type_name, -strlen( 'ToMenuItemConnectionWhereArgs' ) ) === 'ToMenuItemConnectionWhereArgs' ) {
					$fields['language'] = array(
						'type'        => 'LanguageCodeEnum',
						'description' => __( 'The language whose menus to read: at `location`, that language\'s menu there. The default language\'s when left out.', 'gq-polylang-graphql' ),
					);
				}
				return $fields;
			},
			10,
			2
		);
	}

	/**
	 * Points a menu item query at the requested language's menus.
	 *
	 * @param array<string,mixed> $query_args      The WP_Query arguments.
	 * @param mixed               $resolver        The connection resolver.
	 * @param array<string,mixed> $unfiltered_args The connection's GraphQL arguments.
	 * @return array<string,mixed>
	 */
	public static function query_args( $query_args, $resolver, $unfiltered_args ) {
		if ( ! $resolver instanceof MenuItemConnectionResolver || ! is_array( $query_args ) ) {
			return $query_args;
		}
		$where = is_array( $unfiltered_args ) && isset( $unfiltered_args['where'] ) && is_array( $unfiltered_args['where'] ) ? $unfiltered_args['where'] : array();
		if ( empty( $where['language'] ) ) {
			return $query_args;
		}

		$menu_ids = self::menu_ids( (string) $where['language'], isset( $where['location'] ) ? (string) $where['location'] : null );

		$tax_query = isset( $query_args['tax_query'] ) && is_array( $query_args['tax_query'] ) ? $query_args['tax_query'] : array();
		$tax_query = array_values(
			array_filter(
				$tax_query,
				static function ( $clause ) {
					return ! ( is_array( $clause ) && isset( $clause['taxonomy'] ) && 'nav_menu' === $clause['taxonomy'] );
				}
			)
		);
		// No menu means no items, as WPGraphQL does for an empty location.
		$tax_query[]             = array(
			'taxonomy'         => 'nav_menu',
			'field'            => 'term_id',
			'terms'            => empty( $menu_ids ) ? array( 0 ) : $menu_ids,
			'include_children' => false,
			'operator'         => 'IN',
		);
		$query_args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		return Content::without_where_args( $query_args );
	}

	/**
	 * The menu IDs Polylang assigned for a language, at one location or all.
	 *
	 * @param string      $slug     The language slug.
	 * @param string|null $location A theme location, or null for every one.
	 * @return int[]
	 */
	public static function menu_ids( string $slug, ?string $location ): array {
		$locations = self::theme_locations();
		if ( null !== $location ) {
			$locations = isset( $locations[ $location ] ) ? array( $locations[ $location ] ) : array();
		}

		$ids = array();
		foreach ( $locations as $by_language ) {
			if ( is_array( $by_language ) && ! empty( $by_language[ $slug ] ) ) {
				$ids[] = (int) $by_language[ $slug ];
			}
		}
		return array_values( array_unique( $ids ) );
	}
}
