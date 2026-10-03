<?php
/**
 * Languages and translations on posts and terms, and language filters on
 * their connections.
 *
 * @package GQ\PolylangGraphQL
 */

namespace GQ\PolylangGraphQL;

use WPGraphQL\AppContext;

defined( 'ABSPATH' ) || exit;

/**
 * For each post type and taxonomy Polylang translates (and WPGraphQL shows),
 * its GraphQL type gets `language`, `translations` and
 * `translation(language:)`, and the connections to it get `language` and
 * `languages` where-arguments. Without one, a connection returns every
 * language (Polylang's REST context filters nothing; see Context).
 * Translations are loaded through WPGraphQL's loaders, so one the requester
 * can't see (a draft, a private page) is left out.
 */
final class Content {

	/**
	 * Hooks the connection filters.
	 */
	public static function hook(): void {
		add_filter( 'graphql_input_fields', array( self::class, 'where_fields' ), 10, 2 );
		add_filter( 'graphql_post_object_connection_query_args', array( self::class, 'post_query_args' ), 10, 3 );
		add_filter( 'graphql_term_object_connection_query_args', array( self::class, 'term_query_args' ), 10, 3 );
	}

	/**
	 * The GraphQL type names of the translated post types.
	 *
	 * @return string[]
	 */
	public static function post_type_names(): array {
		return self::type_names( (array) PLL()->model->get_translated_post_types(), 'post' );
	}

	/**
	 * The GraphQL type names of the translated taxonomies.
	 *
	 * @return string[]
	 */
	public static function taxonomy_names(): array {
		return self::type_names( (array) PLL()->model->get_translated_taxonomies(), 'term' );
	}

	/**
	 * Registers the fields on each translated type.
	 */
	public static function register_fields(): void {
		foreach ( self::post_type_names() as $type_name ) {
			self::register_translation_fields( $type_name, 'post' );
		}
		foreach ( self::taxonomy_names() as $type_name ) {
			self::register_translation_fields( $type_name, 'term' );
		}
	}

	/**
	 * Adds `language` and `languages` to the where-arguments of connections
	 * to translated types (and to every content node).
	 *
	 * @param array<string,mixed> $fields    The input type's fields.
	 * @param string              $type_name The input type's name.
	 * @return array<string,mixed>
	 */
	public static function where_fields( $fields, $type_name ) {
		if ( ! is_array( $fields ) || ! self::is_translated_where_type( (string) $type_name ) ) {
			return $fields;
		}
		$fields['language']  = array(
			'type'        => 'LanguageCodeFilterEnum',
			'description' => __( 'Only items in this language. Every language when left out.', 'gq-polylang-graphql' ),
		);
		$fields['languages'] = array(
			'type'        => array( 'list_of' => array( 'non_null' => 'LanguageCodeEnum' ) ),
			'description' => __( 'Only items in one of these languages.', 'gq-polylang-graphql' ),
		);
		return $fields;
	}

	/**
	 * Applies the language where-arguments to a post connection's WP_Query.
	 *
	 * @param array<string,mixed> $query_args The WP_Query arguments.
	 * @param mixed               $source     The connection's source.
	 * @param array<string,mixed> $args       The connection's GraphQL arguments.
	 * @return array<string,mixed>
	 */
	public static function post_query_args( $query_args, $source, $args ) {
		// Menu items are posts without a language: Menus handles `language`.
		if ( is_array( $query_args ) && in_array( 'nav_menu_item', (array) ( $query_args['post_type'] ?? array() ), true ) ) {
			return self::without_where_args( $query_args );
		}
		return self::with_language( $query_args, $args );
	}

	/**
	 * Applies the language where-arguments to a term connection's get_terms().
	 *
	 * @param array<string,mixed> $query_args The get_terms() arguments.
	 * @param mixed               $source     The connection's source.
	 * @param array<string,mixed> $args       The connection's GraphQL arguments.
	 * @return array<string,mixed>
	 */
	public static function term_query_args( $query_args, $source, $args ) {
		return self::with_language( $query_args, $args );
	}

	/**
	 * The query arguments with Polylang's `lang` set from the where-arguments.
	 *
	 * @param array<string,mixed> $query_args The query arguments.
	 * @param array<string,mixed> $args       The connection's GraphQL arguments.
	 * @return array<string,mixed>
	 */
	private static function with_language( $query_args, $args ) {
		$lang       = self::lang_query_var( is_array( $args ) && isset( $args['where'] ) && is_array( $args['where'] ) ? $args['where'] : array() );
		$query_args = self::without_where_args( $query_args );
		if ( null !== $lang && is_array( $query_args ) ) {
			$query_args['lang'] = $lang;
		}
		return $query_args;
	}

	/**
	 * The query arguments without the `language` and `languages`
	 * where-arguments, which WPGraphQL copies into them as they are.
	 *
	 * @param array<string,mixed> $query_args The query arguments.
	 * @return array<string,mixed>
	 */
	public static function without_where_args( $query_args ) {
		if ( is_array( $query_args ) ) {
			unset( $query_args['language'], $query_args['languages'] );
		}
		return $query_args;
	}

	/**
	 * Polylang's `lang` query var for the where-arguments: a slug, slugs
	 * joined by commas, '' for every language, or null to leave it alone.
	 *
	 * @param array<string,mixed> $where The where-arguments.
	 */
	public static function lang_query_var( array $where ): ?string {
		if ( ! empty( $where['languages'] ) && is_array( $where['languages'] ) ) {
			return implode( ',', array_map( 'strval', $where['languages'] ) );
		}
		if ( empty( $where['language'] ) ) {
			return null;
		}
		if ( Schema::FILTER_ALL === $where['language'] ) {
			return '';
		}
		if ( Schema::FILTER_DEFAULT === $where['language'] ) {
			return (string) Languages::default_slug();
		}
		return (string) $where['language'];
	}

	/**
	 * Registers `language`, `translations` and `translation` on a type.
	 *
	 * @param string $type_name The GraphQL type name.
	 * @param string $kind      'post' or 'term'.
	 */
	private static function register_translation_fields( string $type_name, string $kind ): void {
		register_graphql_field(
			$type_name,
			'language',
			array(
				'type'        => 'Language',
				'description' => __( 'The language Polylang assigned.', 'gq-polylang-graphql' ),
				'resolve'     => static function ( $node ) use ( $kind ) {
					$slug     = self::language_of( $kind, (int) $node->databaseId );
					$language = $slug ? Languages::get( $slug ) : null;
					return $language ? Languages::to_node( $language ) : null;
				},
			)
		);

		register_graphql_field(
			$type_name,
			'translations',
			array(
				'type'        => array( 'list_of' => $type_name ),
				'description' => __( 'Its translations into the other languages that the requester can see.', 'gq-polylang-graphql' ),
				'resolve'     => static function ( $node, $args, AppContext $context ) use ( $kind ) {
					$id  = (int) $node->databaseId;
					$ids = array_values(
						array_filter(
							array_map( 'intval', self::translations_of( $kind, $id ) ),
							static function ( $translation_id ) use ( $id ) {
								return $translation_id !== $id;
							}
						)
					);
					if ( empty( $ids ) ) {
						return array();
					}
					// A translation the requester can't see loads as null.
					return array_values( array_filter( (array) $context->get_loader( $kind )->load_many( $ids, true ) ) );
				},
			)
		);

		register_graphql_field(
			$type_name,
			'translation',
			array(
				'type'        => $type_name,
				'description' => __( 'Its translation into a language, if the requester can see one.', 'gq-polylang-graphql' ),
				'args'        => array(
					'language' => array( 'type' => array( 'non_null' => 'LanguageCodeEnum' ) ),
				),
				'resolve'     => static function ( $node, array $args, AppContext $context ) use ( $kind ) {
					$id = 'post' === $kind
						? pll_get_post( (int) $node->databaseId, $args['language'] )
						: pll_get_term( (int) $node->databaseId, $args['language'] );
					return $id ? $context->get_loader( $kind )->load_deferred( (int) $id ) : null;
				},
			)
		);
	}

	/**
	 * The language slug of a post or term.
	 *
	 * @param string $kind 'post' or 'term'.
	 * @param int    $id   Its ID.
	 */
	private static function language_of( string $kind, int $id ): ?string {
		$slug = 'post' === $kind ? pll_get_post_language( $id, 'slug' ) : pll_get_term_language( $id, 'slug' );
		return is_string( $slug ) && '' !== $slug ? $slug : null;
	}

	/**
	 * A post's or term's translations, by language slug (itself included).
	 *
	 * @param string $kind 'post' or 'term'.
	 * @param int    $id   Its ID.
	 * @return array<string,int>
	 */
	private static function translations_of( string $kind, int $id ): array {
		return (array) ( 'post' === $kind ? pll_get_post_translations( $id ) : pll_get_term_translations( $id ) );
	}

	/**
	 * Whether a where-arguments input type belongs to a connection to a
	 * translated type: its name ends in `To<Type>ConnectionWhereArgs`.
	 *
	 * @param string $type_name The input type's name.
	 */
	private static function is_translated_where_type( string $type_name ): bool {
		static $suffixes = null;
		if ( null === $suffixes ) {
			$suffixes = array( 'ToContentNodeConnectionWhereArgs' );
			foreach ( array_merge( self::post_type_names(), self::taxonomy_names() ) as $name ) {
				$suffixes[] = 'To' . $name . 'ConnectionWhereArgs';
			}
		}
		foreach ( $suffixes as $suffix ) {
			if ( substr( $type_name, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * GraphQL type names of the given post types or taxonomies that WPGraphQL
	 * shows.
	 *
	 * @param string[] $names Post type or taxonomy names.
	 * @param string   $kind  'post' or 'term'.
	 * @return string[]
	 */
	private static function type_names( array $names, string $kind ): array {
		$type_names = array();
		foreach ( $names as $name ) {
			$object = 'post' === $kind ? get_post_type_object( $name ) : get_taxonomy( $name );
			if ( $object && ! empty( $object->show_in_graphql ) && ! empty( $object->graphql_single_name ) ) {
				$type_names[] = ucfirst( (string) $object->graphql_single_name );
			}
		}
		return array_values( array_unique( $type_names ) );
	}
}
