<?php
/**
 * Each language's translation of the static front page.
 *
 * @package GQ\PolylangGraphQL
 */

namespace GQ\PolylangGraphQL;

use WPGraphQL\AppContext;

defined( 'ABSPATH' ) || exit;

/**
 * WPGraphQL knows one front page, `page_on_front`. With Polylang each
 * language has its own translation of it, served at the language's home
 * (/en/). So:
 *
 * - `nodeByUri` resolves a language's home path to that language's front page;
 * - a translated front page has `isFrontPage: true` and its language's home
 *   path as `uri` (/en/), as the default language's has `/`.
 */
final class Front_Pages {

	/**
	 * Synchronous resolver scopes, discarded even on early returns/exceptions.
	 * Deferred loaders run later; they must not read this state.
	 *
	 * @var string[]
	 */
	private static $languages = array();

	/**
	 * Whether the next pre-hook is our delegated resolver call.
	 *
	 * @var bool
	 */
	private static $delegating = false;

	/**
	 * Hooks the URI resolver and the model fields.
	 */
	public static function hook(): void {
		add_filter( 'graphql_pre_resolve_uri', array( self::class, 'resolve_uri' ), 10, 5 );
		add_filter( 'graphql_model_prepare_fields', array( self::class, 'model_fields' ), 10, 3 );
		add_filter( 'graphql_resolve_uri', array( self::class, 'check_language' ), 10, 3 );
	}

	/**
	 * Keeps a URI from resolving to content in another language than its own
	 * (/en/sobre/ to the Portuguese Sobre, /about/ to the English About), or
	 * to Polylang's language archive, which WPGraphQL has no type for.
	 * WordPress finds a page by its path whatever the language prefix says.
	 * This runs before WPGraphQL turns what WordPress found into a node, where
	 * null means "carry on", so a refusal is a node that resolves to null.
	 *
	 * @param mixed  $node           A node another filter resolved, or null.
	 * @param string $uri            The URI being resolved.
	 * @param mixed  $queried_object The object WordPress's query found.
	 * @return mixed
	 */
	public static function check_language( $node, $uri, $queried_object ) {
		if ( null !== $node ) {
			return $node;
		}
		$nothing = new \GraphQL\Deferred(
			static function () {
				return null;
			}
		);
		if ( $queried_object instanceof \WP_Term && 'language' === $queried_object->taxonomy ) {
			return $nothing;
		}
		$expected = self::$languages ? end( self::$languages ) : Languages::of_url( (string) $uri );
		if ( null === $expected ) {
			return $node;
		}
		$actual = null;
		if ( $queried_object instanceof \WP_Post && pll_is_translated_post_type( $queried_object->post_type ) ) {
			$actual = pll_get_post_language( $queried_object->ID, 'slug' );
		} elseif ( $queried_object instanceof \WP_Term && pll_is_translated_taxonomy( $queried_object->taxonomy ) ) {
			$actual = pll_get_term_language( $queried_object->term_id, 'slug' );
		}
		return is_string( $actual ) && '' !== $actual && $actual !== $expected ? $nothing : $node;
	}

	/**
	 * The ID of a language's translation of the static front page, or null
	 * when the site shows posts on its front page or the language has none.
	 *
	 * @param string $slug The language slug.
	 */
	public static function front_page_id( string $slug ): ?int {
		$front_page = self::front_page();
		if ( ! $front_page ) {
			return null;
		}
		$id = pll_get_post( $front_page, $slug );
		return $id ? (int) $id : null;
	}

	/**
	 * Resolves a language's home path to its front page.
	 *
	 * @param mixed      $node    A node another filter resolved, or null.
	 * @param string     $uri     The URI being resolved.
	 * @param AppContext $context The request's context.
	 * @param mixed      $wp      WordPress request object.
	 * @param mixed      $extra   Additional resolver query variables.
	 * @return mixed
	 */
	public static function resolve_uri( $node, $uri, $context, $wp = null, $extra = '' ) {
		if ( self::$delegating ) {
			self::$delegating = false;
			return $node;
		}
		if ( null !== $node || ! is_string( $uri ) || ! $context instanceof AppContext ) {
			return $node;
		}
		$slug = Languages::of_url( $uri );
		if ( null === $slug ) {
			// Without a unique URL language, never let WordPress guess a front
			// page or translated node. Unconfigured sites keep native resolution.
			return Languages::all() ? self::nothing() : $node;
		}
		$selectors = $extra;
		if ( is_string( $selectors ) ) {
			parse_str( $selectors, $selectors );
		}
		if ( is_array( $selectors ) ) {
			unset( $selectors['nodeType'], $selectors['asPreview'], $selectors['lang'] );
		}
		if ( empty( $selectors ) && Languages::is_home_url( $uri, $slug ) ) {
			$page_id = self::front_page_id( $slug );
			if ( $page_id ) {
				return $context->get_loader( 'post' )->load_deferred( $page_id );
			}
		}

		// NodeResolver rejects secondary domains and loses host/query before its
		// post hook. Give it a local URI, keeping the original language in a
		// try/finally scope rather than a last-URI cache shared by aliases.
		if ( PLL()->links_model instanceof \PLL_Links_Abstract_Domain ) {
			if ( ! is_array( $extra ) ) {
				parse_str( (string) $extra, $extra );
			}
			$extra['lang'] = $slug;
		}
		$parts = wp_parse_url( $uri );
		$local = $parts['path'] ?? '/';
		if ( isset( $parts['query'] ) ) {
			// A root path makes NodeResolver bypass WP_Query, even with selectors.
			if ( '/' === Languages::site_path( $local ) ) {
				$local = '';
			}
			$local .= '?' . $parts['query'];
		}
		if ( '/' === Languages::site_path( $local ) && ! empty( $selectors ) ) {
			$local = '?' . ( is_array( $selectors ) ? http_build_query( $selectors ) : '' );
		}
		if ( isset( $parts['fragment'] ) ) {
			$local .= '#' . $parts['fragment'];
		}
		// Pretty rewrite rules can mistake a query-only URI for a post slug.
		// Keep public query selectors parsed by NodeResolver, not that synthetic
		// name (or its rewrite 404). Never promote URL args to private query vars.
		$query_only = 0 === strpos( $local, '?' );
		$fix_query  = static function ( $vars ) use ( $query_only ) {
			if ( $query_only && is_array( $vars ) ) {
				foreach ( array( 'name', 'pagename' ) as $key ) {
					if ( isset( $vars[ $key ], $vars['uri'] ) && $vars['uri'] === $vars[ $key ] ) {
						unset( $vars[ $key ] );
					}
				}
				unset( $vars['error'] );
			}
			return $vars;
		};
		add_filter( 'request', $fix_query, -PHP_INT_MAX );
		self::$languages[] = $slug;
		self::$delegating  = true;
		try {
			$resolved = ( new \WPGraphQL\Data\NodeResolver( $context ) )->resolve_uri( $local, $extra );
			return null === $resolved ? self::nothing() : $resolved;
		} finally {
			remove_filter( 'request', $fix_query, -PHP_INT_MAX );
			array_pop( self::$languages );
			self::$delegating = false;
		}
	}

	/**
	 * A non-null refusal prevents NodeResolver from continuing its fallback.
	 *
	 * @return \GraphQL\Deferred
	 */
	private static function nothing() {
		return new \GraphQL\Deferred(
			static function () {
				return null;
			}
		);
	}

	/**
	 * Makes a translated front page a front page, at its language's home.
	 *
	 * @param array<string,mixed> $fields     The model's fields.
	 * @param string              $model_name The model's name.
	 * @param mixed               $data       The model's data.
	 * @return array<string,mixed>
	 */
	public static function model_fields( $fields, $model_name, $data ) {
		if ( 'PostObject' !== $model_name || ! $data instanceof \WP_Post || 'page' !== $data->post_type ) {
			return $fields;
		}
		$slug = self::translated_front_page_language( $data->ID );
		if ( null === $slug ) {
			return $fields;
		}
		$fields['isFrontPage'] = static function () {
			return true;
		};
		$fields['uri']         = static function () use ( $slug ) {
			return Languages::home_path( $slug );
		};
		return $fields;
	}

	/**
	 * The language of the page if it is a translation of the front page.
	 *
	 * @param int $page_id A page ID.
	 */
	private static function translated_front_page_language( int $page_id ): ?string {
		$front_page = self::front_page();
		if ( ! $front_page ) {
			return null;
		}
		$translations = (array) pll_get_post_translations( $front_page );
		$slug         = array_search( $page_id, array_map( 'intval', $translations ), true );
		return is_string( $slug ) ? $slug : null;
	}

	/**
	 * The static front page's ID as stored (in the default language), or 0.
	 */
	private static function front_page(): int {
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			return 0;
		}
		return (int) get_option( 'page_on_front' );
	}
}
