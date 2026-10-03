<?php
/**
 * Runs Polylang in its REST API context during GraphQL requests.
 *
 * @package GQ\PolylangGraphQL
 */

namespace GQ\PolylangGraphQL;

defined( 'ABSPATH' ) || exit;

/**
 * Polylang picks a context when it initializes: the frontend one detects a
 * language from the URL and filters every query by it, which would hide all
 * but one language from a GraphQL request to /graphql. Its REST context suits
 * an API: it filters links (an English page's link is /en/…), sets up each
 * language's static front page and filters nothing until a language is asked
 * for. So a GraphQL request gets the REST context, and queries choose their
 * language through this plugin's `language` arguments.
 */
final class Context {

	/**
	 * Hooks the context choice. Must run before Polylang initializes.
	 */
	public static function hook(): void {
		add_filter( 'pll_context', array( self::class, 'choose' ) );
	}

	/**
	 * Polylang's context class for this request.
	 *
	 * @param string $class_name The class Polylang chose.
	 * @return string
	 */
	public static function choose( $class_name ) {
		if ( 'PLL_Frontend' === $class_name && self::is_graphql_request() ) {
			return 'PLL_REST_Request';
		}
		return $class_name;
	}

	/**
	 * Whether this is a GraphQL HTTP request, decided before WPGraphQL routes
	 * it, the way WPGraphQL's Router does: `?graphql`, or a path that is its
	 * endpoint under the site URL.
	 */
	public static function is_graphql_request(): bool {
		/**
		 * Filters whether the current request is a GraphQL request. Null keeps
		 * the default detection.
		 *
		 * @param bool|null $is_graphql_request Whether it is a GraphQL request.
		 */
		$pre = apply_filters( 'gq_polylang_graphql_is_graphql_request', null );
		if ( null !== $pre ) {
			return (bool) $pre;
		}

		if ( defined( 'GRAPHQL_HTTP_REQUEST' ) && GRAPHQL_HTTP_REQUEST ) {
			return true;
		}

		// The endpoint as WPGraphQL's settings and `graphql_endpoint` filter set it.
		$route = function_exists( 'graphql_get_endpoint' ) ? (string) graphql_get_endpoint() : (string) apply_filters( 'graphql_endpoint', 'graphql' );
		if ( isset( $_GET[ $route ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}
		if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		$request_path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$graphql_path = (string) wp_parse_url( site_url( $route ), PHP_URL_PATH );
		return '' !== $request_path && trim( $request_path, '/' ) === trim( $graphql_path, '/' );
	}
}
