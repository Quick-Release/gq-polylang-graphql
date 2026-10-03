<?php
/**
 * Polylang's languages, as the schema names them.
 *
 * @package GQ\PolylangGraphQL
 */

namespace GQ\PolylangGraphQL;

defined( 'ABSPATH' ) || exit;

/**
 * Reads Polylang's languages through its public API (pll_* functions and
 * PLL()->model), and maps them to the schema's names: a language's code is its
 * Polylang slug in upper case (`pt` → `PT`, `pt-br` → `PT_BR`).
 */
final class Languages {

	/**
	 * Polylang's languages, in its order.
	 *
	 * @return \PLL_Language[]
	 */
	public static function all(): array {
		if ( ! function_exists( 'PLL' ) || ! isset( PLL()->model ) ) {
			return array();
		}
		return PLL()->model->get_languages_list();
	}

	/**
	 * A language by its Polylang slug.
	 *
	 * @param string $slug The language slug.
	 * @return \PLL_Language|null
	 */
	public static function get( string $slug ) {
		foreach ( self::all() as $language ) {
			if ( $language->slug === $slug ) {
				return $language;
			}
		}
		return null;
	}

	/**
	 * The default language's slug, or null before one exists.
	 */
	public static function default_slug(): ?string {
		$slug = pll_default_language( 'slug' );
		return is_string( $slug ) && '' !== $slug ? $slug : null;
	}

	/**
	 * The schema's enum value name for a language slug.
	 *
	 * @param string $slug The language slug.
	 */
	public static function code( string $slug ): string {
		return strtoupper( (string) preg_replace( '/[^A-Za-z0-9_]/', '_', $slug ) );
	}

	/**
	 * A language's path from the site root to its home, with slashes on both
	 * ends: `/` for a language whose URLs carry no prefix, `/en/` otherwise.
	 *
	 * @param string $slug The language slug.
	 */
	public static function home_path( string $slug ): string {
		$home = (string) wp_parse_url( self::home_url( $slug ), PHP_URL_PATH );
		$root = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$path = '/' . ltrim( substr( trailingslashit( $home ), strlen( rtrim( $root, '/' ) ) ), '/' );
		return trailingslashit( $path );
	}

	/**
	 * A language's home URL: the site's home with the language's URL prefix
	 * (https://example.com/en/). Unlike pll_home_url(), never the front
	 * page's own URL (/en/home/), which Polylang gives when its "front page
	 * URL contains the language code" option is off: a headless site serves a
	 * language's front page at the language's home.
	 *
	 * @param string $slug The language slug.
	 */
	public static function home_url( string $slug ): string {
		return (string) PLL()->links_model->home_url( $slug );
	}

	/**
	 * The language a path from the site root belongs to: the language whose
	 * URL prefix starts it, otherwise the language without a prefix (the
	 * default one, when it is hidden), otherwise none.
	 *
	 * @param string $path A path from the site root.
	 */
	public static function of_path( string $path ): ?string {
		$path     = trailingslashit( '/' . ltrim( $path, '/' ) );
		$unprefix = null;
		foreach ( self::all() as $language ) {
			$home = self::home_path( $language->slug );
			if ( '/' === $home ) {
				$unprefix = $language->slug;
			} elseif ( 0 === strpos( $path, $home ) ) {
				return $language->slug;
			}
		}
		return $unprefix;
	}

	/**
	 * The language whose home is `$path` (as in home_path), if any.
	 *
	 * @param string $path A path from the site root.
	 * @return \PLL_Language|null
	 */
	public static function by_home_path( string $path ) {
		$path = trailingslashit( '/' . ltrim( $path, '/' ) );
		foreach ( self::all() as $language ) {
			if ( self::home_path( $language->slug ) === $path ) {
				return $language;
			}
		}
		return null;
	}

	/**
	 * The fields of the schema's Language type for a language.
	 *
	 * @param \PLL_Language $language The language.
	 * @return array<string,mixed>
	 */
	public static function to_node( $language ): array {
		return array(
			'id'        => \GraphQLRelay\Relay::toGlobalId( 'language', $language->slug ),
			// An enum field holds the enum's value, the slug; GraphQL shows its name.
			'code'      => $language->slug,
			'slug'      => $language->slug,
			'locale'    => $language->locale,
			'name'      => $language->name,
			'isDefault' => self::default_slug() === $language->slug,
			'homeUrl'   => self::home_url( $language->slug ),
			'uri'       => self::home_path( $language->slug ),
		);
	}
}
