<?php
/**
 * The languages part of the schema: types, enums and root fields.
 *
 * @package GQ\PolylangGraphQL
 */

namespace GQ\PolylangGraphQL;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Language type, the LanguageCodeEnum and
 * LanguageCodeFilterEnum enums (one value per Polylang language) and the
 * root fields `languages`, `defaultLanguage`, `language` and
 * `translateString`. Names follow valu-digital/wp-graphql-polylang's, so
 * queries written for it keep working.
 */
final class Schema {

	/** LanguageCodeFilterEnum's value for the default language. */
	public const FILTER_DEFAULT = '__default__';

	/** LanguageCodeFilterEnum's value for every language. */
	public const FILTER_ALL = '__all__';

	/**
	 * Registers everything; a site without languages yet gets none of it,
	 * since an enum needs values.
	 */
	public static function register(): void {
		$languages = Languages::all();
		if ( empty( $languages ) ) {
			return;
		}

		$codes = array();
		foreach ( $languages as $language ) {
			$codes[ Languages::code( $language->slug ) ] = array(
				'value'       => $language->slug,
				'description' => $language->name,
			);
		}

		register_graphql_enum_type(
			'LanguageCodeEnum',
			array(
				'description' => __( 'A language Polylang serves, by its code (its slug in upper case).', 'gq-polylang-graphql' ),
				'values'      => $codes,
			)
		);

		register_graphql_enum_type(
			'LanguageCodeFilterEnum',
			array(
				'description' => __( 'A language to filter by: a language code, DEFAULT for the default language, or ALL for every language.', 'gq-polylang-graphql' ),
				'values'      => $codes + array(
					'DEFAULT' => array(
						'value'       => self::FILTER_DEFAULT,
						'description' => __( 'The default language.', 'gq-polylang-graphql' ),
					),
					'ALL'     => array(
						'value'       => self::FILTER_ALL,
						'description' => __( 'Every language.', 'gq-polylang-graphql' ),
					),
				),
			)
		);

		register_graphql_object_type(
			'Language',
			array(
				'description' => __( 'A language Polylang serves.', 'gq-polylang-graphql' ),
				'fields'      => array(
					'id'          => array(
						'type'        => array( 'non_null' => 'ID' ),
						'description' => __( 'A global ID for the language.', 'gq-polylang-graphql' ),
					),
					'code'        => array(
						'type'        => array( 'non_null' => 'LanguageCodeEnum' ),
						'description' => __( 'The language code.', 'gq-polylang-graphql' ),
					),
					'slug'        => array(
						'type'        => array( 'non_null' => 'String' ),
						'description' => __( 'Polylang\'s slug for the language, as used in URLs (en).', 'gq-polylang-graphql' ),
					),
					'locale'      => array(
						'type'        => array( 'non_null' => 'String' ),
						'description' => __( 'The WordPress locale (en_US, pt_PT_ao90).', 'gq-polylang-graphql' ),
					),
					'name'        => array(
						'type'        => array( 'non_null' => 'String' ),
						'description' => __( 'The language\'s name, in that language.', 'gq-polylang-graphql' ),
					),
					'isDefault'   => array(
						'type'        => array( 'non_null' => 'Boolean' ),
						'description' => __( 'Whether this is the default language.', 'gq-polylang-graphql' ),
					),
					'homeUrl'     => array(
						'type'        => 'String',
						'description' => __( 'The URL of the language\'s home.', 'gq-polylang-graphql' ),
					),
					'uri'         => array(
						'type'        => 'String',
						'description' => __( 'The path of the language\'s home from the site root: / without a URL prefix, /en/ with one.', 'gq-polylang-graphql' ),
					),
					'title'       => array(
						'type'        => 'String',
						'description' => __( 'The site title, translated into the language (Polylang\'s string translations).', 'gq-polylang-graphql' ),
						'resolve'     => static function ( $language ) {
							return self::translate( (string) get_option( 'blogname' ), $language['slug'] );
						},
					),
					'description' => array(
						'type'        => 'String',
						'description' => __( 'The tagline, translated into the language (Polylang\'s string translations).', 'gq-polylang-graphql' ),
						'resolve'     => static function ( $language ) {
							return self::translate( (string) get_option( 'blogdescription' ), $language['slug'] );
						},
					),
					'frontPage'   => array(
						'type'        => 'Page',
						'description' => __( 'The language\'s translation of the static front page, if the site has one and it is translated.', 'gq-polylang-graphql' ),
						'resolve'     => static function ( $language, $args, $context ) {
							$page_id = Front_Pages::front_page_id( $language['slug'] );
							return $page_id ? $context->get_loader( 'post' )->load_deferred( $page_id ) : null;
						},
					),
				),
			)
		);

		register_graphql_field(
			'RootQuery',
			'languages',
			array(
				'type'        => array( 'non_null' => array( 'list_of' => array( 'non_null' => 'Language' ) ) ),
				'description' => __( 'Every language Polylang serves, in its order.', 'gq-polylang-graphql' ),
				'resolve'     => static function () {
					return array_map( array( Languages::class, 'to_node' ), Languages::all() );
				},
			)
		);

		register_graphql_field(
			'RootQuery',
			'defaultLanguage',
			array(
				'type'        => 'Language',
				'description' => __( 'The default language.', 'gq-polylang-graphql' ),
				'resolve'     => static function () {
					$slug     = Languages::default_slug();
					$language = $slug ? Languages::get( $slug ) : null;
					return $language ? Languages::to_node( $language ) : null;
				},
			)
		);

		register_graphql_field(
			'RootQuery',
			'language',
			array(
				'type'        => 'Language',
				'description' => __( 'A language by its code.', 'gq-polylang-graphql' ),
				'args'        => array(
					'code' => array( 'type' => array( 'non_null' => 'LanguageCodeEnum' ) ),
				),
				'resolve'     => static function ( $source, array $args ) {
					$language = Languages::get( $args['code'] );
					return $language ? Languages::to_node( $language ) : null;
				},
			)
		);

		register_graphql_field(
			'RootQuery',
			'translateString',
			array(
				'type'        => 'String',
				'description' => __( 'A string translated into a language through Polylang\'s string translations; the string itself when it has no translation.', 'gq-polylang-graphql' ),
				'args'        => array(
					'string'   => array( 'type' => array( 'non_null' => 'String' ) ),
					'language' => array( 'type' => array( 'non_null' => 'LanguageCodeEnum' ) ),
				),
				'resolve'     => static function ( $source, array $args ) {
					return self::translate( $args['string'], $args['language'] );
				},
			)
		);

		Content::register_fields();
		Menus::register_fields();
	}

	/**
	 * A string translated into the language with `$slug`.
	 *
	 * @param string $text The string.
	 * @param string $slug The language slug.
	 */
	private static function translate( string $text, string $slug ): string {
		return '' === $text ? '' : (string) pll_translate_string( $text, $slug );
	}
}
