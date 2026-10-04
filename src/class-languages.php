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
 * PLL()->model), and maps them to collision-safe schema names. Unique ordinary
 * slugs retain their normalized upper-case names (`pt` → `PT`, `pt-br` → `PT_BR`).
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
	 * Configured slugs use the same allocation as registration. An unknown slug
	 * is allocated as an additional language without changing configured state.
	 *
	 * @param string $slug The language slug.
	 */
	public static function code( string $slug ): string {
		$slugs   = array_map(
			static function ( $language ) {
				return $language->slug;
			},
			self::all()
		);
		$slugs[] = $slug;
		return self::allocate_codes( $slugs )[ $slug ];
	}

	/**
	 * Slug-to-enum names shared by both language enums, without cached state.
	 *
	 * Passing the registration's language snapshot avoids a second model read.
	 * All members of normalized collision groups and ALL/DEFAULT get a suffix
	 * containing the full slug's uppercase hexadecimal bytes. Ordinary names
	 * are reserved first; generated names append underscores until unused.
	 *
	 * @param \PLL_Language[]|null $languages Languages to map, or the configured set.
	 * @return array<string,string>
	 */
	public static function code_map( ?array $languages = null ): array {
		$slugs = array_map(
			static function ( $language ) {
				return $language->slug;
			},
			$languages ?? self::all()
		);
		return self::allocate_codes( $slugs );
	}

	/**
	 * Allocate deterministically, independent of Polylang's language order.
	 *
	 * @param string[] $slugs Raw language slugs.
	 * @return array<string,string>
	 */
	private static function allocate_codes( array $slugs ): array {
		$slugs = array_values( array_unique( $slugs ) );
		sort( $slugs, SORT_STRING );
		$bases  = array();
		$counts = array();
		foreach ( $slugs as $slug ) {
			$base            = self::normalized_code( $slug );
			$bases[ $slug ]  = $base;
			$counts[ $base ] = ( $counts[ $base ] ?? 0 ) + 1;
		}

		$used  = array(
			'ALL'     => true,
			'DEFAULT' => true,
		);
		$codes = array();
		foreach ( $slugs as $slug ) {
			$base = $bases[ $slug ];
			if ( 1 === $counts[ $base ] && ! isset( $used[ $base ] ) ) {
				$codes[ $slug ] = $base;
				$used[ $base ]  = true;
			}
		}
		foreach ( $slugs as $slug ) {
			if ( isset( $codes[ $slug ] ) ) {
				continue;
			}
			$code = $bases[ $slug ] . '__' . strtoupper( bin2hex( $slug ) );
			while ( isset( $used[ $code ] ) ) {
				$code .= '_';
			}
			$codes[ $slug ] = $code;
			$used[ $code ]  = true;
		}
		ksort( $codes, SORT_STRING );
		return $codes;
	}

	/**
	 * Normalize defensively, including slugs outside Polylang's validated set.
	 *
	 * @param string $slug Raw language slug.
	 */
	private static function normalized_code( string $slug ): string {
		$code = strtoupper( (string) preg_replace( '/[^A-Za-z0-9_]/', '_', $slug ) );
		if ( '' === $code || '_' === $code || ctype_digit( $code[0] ) || 0 === strpos( $code, '__' ) ) {
			$code = 'LANG_' . $code;
		}
		return $code;
	}

	/**
	 * A language's path from the site root to its home, with slashes on both
	 * ends: `/` for a language whose URLs carry no prefix, `/en/` otherwise.
	 *
	 * @param string $slug The language slug.
	 */
	public static function home_path( string $slug ): string {
		$home = (string) wp_parse_url( self::home_url( $slug ), PHP_URL_PATH );
		$root = (string) wp_parse_url( PLL()->links_model->home, PHP_URL_PATH );
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
	 * Identify a URL without borrowing the current HTTP request's language.
	 * Relative directory URIs are site-relative, optionally including the site's
	 * subdirectory. Domain URIs need a host; query URIs need an explicit lang.
	 *
	 * @param string $uri Original URI, before NodeResolver discards host/query.
	 */
	public static function of_url( string $uri ): ?string {
		$parts = wp_parse_url( $uri );
		if ( false === $parts || isset( $parts['user'] ) || isset( $parts['pass'] ) || ( isset( $parts['scheme'] ) && ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) ) {
			return null;
		}
		$model = PLL()->links_model;
		$host  = $parts['host'] ?? null;
		$root  = rtrim( (string) wp_parse_url( $model->home, PHP_URL_PATH ), '/' );
		$path  = $parts['path'] ?? '/';
		if ( null !== $host && '' !== $root && $root !== $path && 0 !== strpos( $path, $root . '/' ) ) {
			return null;
		}
		if ( null !== $host && ! in_array( strtolower( $host ), array_map( 'strtolower', array_values( $model->get_hosts() ) ), true ) ) {
			return null;
		}
		$query      = array();
		$lang_count = 0;
		foreach ( explode( '&', $parts['query'] ?? '' ) as $parameter ) {
			$argument = array();
			parse_str( $parameter, $argument );
			if ( array_key_exists( 'lang', $argument ) ) {
				++$lang_count;
			}
		}
		if ( 1 < $lang_count ) {
			// PHP otherwise silently chooses the last duplicate language selector.
			return null;
		}
		parse_str( $parts['query'] ?? '', $query );
		if ( $model instanceof \PLL_Links_Abstract_Domain ) {
			$slug = null === $host ? null : self::known_url_language( $uri );
			return array_key_exists( 'lang', $query ) && $slug !== $query['lang'] ? null : $slug;
		}
		if ( ! $model->using_permalinks ) {
			if ( array_key_exists( 'lang', $query ) ) {
				return is_string( $query['lang'] ) && self::get( $query['lang'] ) ? $query['lang'] : null;
			}
			$default = self::default_slug();
			return null !== $host && null !== $default && false === strpos( self::home_url( $default ), '?' ) ? $default : null;
		}
		$path = self::site_path( $parts['path'] ?? '/' );
		$slug = self::known_url_language( trailingslashit( $model->home ) . ltrim( $path, '/' ) ) ?? self::of_path( $path );
		return array_key_exists( 'lang', $query ) && $slug !== $query['lang'] ? null : $slug;
	}

	/**
	 * Strip the site's subdirectory only at a complete path boundary.
	 *
	 * @param string $path Absolute or site-relative path.
	 */
	public static function site_path( string $path ): string {
		$path = '/' . ltrim( $path, '/' );
		$root = rtrim( (string) wp_parse_url( PLL()->links_model->home, PHP_URL_PATH ), '/' );
		if ( '' !== $root && ( $root === $path || 0 === strpos( $path, $root . '/' ) ) ) {
			$path = substr( $path, strlen( $root ) );
		}
		return '/' . ltrim( $path, '/' );
	}

	/**
	 * A home URL must not contain content selectors or comment fragments.
	 *
	 * @param string $uri Original URL.
	 * @param string $slug Expected language.
	 */
	public static function is_home_url( string $uri, string $slug ): bool {
		$parts = wp_parse_url( $uri );
		if ( false === $parts || isset( $parts['fragment'] ) ) {
			return false;
		}
		$query = array();
		parse_str( $parts['query'] ?? '', $query );
		unset( $query['lang'] );
		if ( $query ) {
			return false;
		}
		$path = trailingslashit( self::site_path( $parts['path'] ?? '/' ) );
		return self::home_path( $slug ) === $path;
	}

	/**
	 * Validate the links model's result against registered languages.
	 *
	 * @param string $url Non-empty URL, never the ambient request URL.
	 */
	private static function known_url_language( string $url ): ?string {
		$slug = PLL()->links_model->get_language_from_url( $url );
		return is_string( $slug ) && self::get( $slug ) ? $slug : null;
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
