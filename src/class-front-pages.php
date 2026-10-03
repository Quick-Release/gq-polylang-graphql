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
	 * Hooks the URI resolver and the model fields.
	 */
	public static function hook(): void {
		add_filter( 'graphql_pre_resolve_uri', array( self::class, 'resolve_uri' ), 10, 3 );
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
		$expected = Languages::of_path( (string) wp_parse_url( (string) $uri, PHP_URL_PATH ) );
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
	 * @return mixed
	 */
	public static function resolve_uri( $node, $uri, $context ) {
		if ( null !== $node || ! is_string( $uri ) || ! $context instanceof AppContext ) {
			return $node;
		}
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		if ( '' === $path ) {
			return $node;
		}
		$language = Languages::by_home_path( $path );
		$page_id  = $language ? self::front_page_id( $language->slug ) : null;
		return $page_id ? $context->get_loader( 'post' )->load_deferred( $page_id ) : $node;
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
