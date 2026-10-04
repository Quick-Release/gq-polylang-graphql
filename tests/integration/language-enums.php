<?php
/**
 * Enum introspection, input resolution, serialization and filter sentinels.
 *
 * @package GQ\PolylangGraphQL
 */

/**
 * Executes GraphQL with variables, failing on errors.
 *
 * @param string              $query GraphQL query.
 * @param array<string,mixed> $variables Input variables.
 * @return array<string,mixed>
 */
function gq_enum_query( string $query, array $variables = array() ): array {
	$result = graphql(
		array(
			'query'     => $query,
			'variables' => $variables,
		)
	);
	if ( ! empty( $result['errors'] ) ) {
		WP_CLI::error( wp_json_encode( $result ) );
	}
	return $result['data'];
}

/**
 * Strict assertion with diagnostics.
 *
 * @param mixed  $actual Actual result.
 * @param mixed  $expected Expected result.
 * @param string $message Assertion label.
 */
function gq_enum_same( $actual, $expected, string $message ): void {
	if ( $actual !== $expected ) {
		WP_CLI::error( $message . ': expected ' . wp_json_encode( $expected ) . ', got ' . wp_json_encode( $actual ) );
	}
}

$gq_expected = array(
	'en'    => 'EN',
	'pt-br' => 'PT_BR',
);
if ( 'basic' !== $args[0] ) {
	$gq_expected = array(
		'en'                 => 'EN',
		'pt-br'              => 'PT_BR__70742D6272__',
		'pt_br'              => 'PT_BR__70745F6272',
		'all'                => 'ALL__616C6C_',
		'default'            => 'DEFAULT__64656661756C74',
		'pt_br__70742d6272'  => 'PT_BR__70742D6272',
		'pt_br__70742d6272_' => 'PT_BR__70742D6272_',
		'all__616c6c'        => 'ALL__616C6C',
	);
}
ksort( $gq_expected );

// Introspection is authorized independently of the anonymous content queries.
wp_set_current_user( (int) get_user_by( 'login', 'admin' )->ID );
$gq_types = gq_enum_query( '{ codes: __type(name: "LanguageCodeEnum") { enumValues { name } } filters: __type(name: "LanguageCodeFilterEnum") { enumValues { name } } }' );
$gq_codes = array_column( $gq_types['codes']['enumValues'], 'name' );
sort( $gq_codes );
$gq_names = array_values( $gq_expected );
sort( $gq_names );
gq_enum_same( $gq_codes, $gq_names, 'Every language appears exactly once in the enum' );
$gq_filters = array_column( $gq_types['filters']['enumValues'], 'name' );
sort( $gq_filters );
$gq_filter_names = array_merge( $gq_names, array( 'ALL', 'DEFAULT' ) );
sort( $gq_filter_names );
gq_enum_same( $gq_filters, $gq_filter_names, 'Filter enum retains both sentinels and every language' );
wp_set_current_user( 0 );

$gq_languages  = gq_enum_query( '{ languages { slug code } defaultLanguage { slug code } }' );
$gq_serialized = array_column( $gq_languages['languages'], 'code', 'slug' );
ksort( $gq_serialized );
gq_enum_same( $gq_serialized, $gq_expected, 'Language.code serializes every collision correctly' );
$gq_default = 'reserved-default' === $args[0] ? 'all' : 'en';
gq_enum_same(
	$gq_languages['defaultLanguage'],
	array(
		'slug' => $gq_default,
		'code' => $gq_expected[ $gq_default ],
	),
	'Default language serialization'
);

$gq_page_ids = get_option( 'gq_language_enum_pages' );
foreach ( $gq_expected as $gq_slug => $gq_code ) {
	gq_enum_same( \GQ\PolylangGraphQL\Languages::code( $gq_slug ), $gq_code, 'Shared code mapper: ' . $gq_slug );
	$gq_input = gq_enum_query( 'query($code: LanguageCodeEnum!) { language(code: $code) { slug code } }', array( 'code' => $gq_code ) );
	gq_enum_same(
		$gq_input['language'],
		array(
			'slug' => $gq_slug,
			'code' => $gq_code,
		),
		'Variable input resolves raw slug: ' . $gq_code
	);
	$gq_literal = gq_enum_query( '{ language(code: ' . $gq_code . ') { slug code } }' );
	gq_enum_same( $gq_literal, $gq_input, 'Literal input matches variable input' );
	$gq_pages = gq_enum_query( 'query($language: LanguageCodeFilterEnum!) { pages(first: 100, where: { language: $language }) { nodes { databaseId language { slug code } } } }', array( 'language' => $gq_code ) );
	gq_enum_same(
		$gq_pages['pages']['nodes'],
		array(
			array(
				'databaseId' => $gq_page_ids[ $gq_slug ],
				'language'   => array(
					'slug' => $gq_slug,
					'code' => $gq_code,
				),
			),
		),
		'Filter input identifies one language: ' . $gq_code
	);
	$gq_plural = gq_enum_query( 'query($languages: [LanguageCodeEnum!]) { pages(first: 100, where: { languages: $languages }) { nodes { databaseId } } }', array( 'languages' => array( $gq_code ) ) );
	gq_enum_same( array_column( $gq_plural['pages']['nodes'], 'databaseId' ), array( $gq_page_ids[ $gq_slug ] ), 'Plural language inputs: ' . $gq_code );
}
if ( 'basic' !== $args[0] ) {
	$gq_mixed     = gq_enum_query( 'query($languages: [LanguageCodeEnum!]) { pages(first: 100, where: { languages: $languages }) { nodes { databaseId } } }', array( 'languages' => array( $gq_expected['all'], 'EN' ) ) );
	$gq_mixed_ids = array_column( $gq_mixed['pages']['nodes'], 'databaseId' );
	sort( $gq_mixed_ids );
	$gq_mixed_expected = array( $gq_page_ids['all'], $gq_page_ids['en'] );
	sort( $gq_mixed_expected );
	gq_enum_same( $gq_mixed_ids, $gq_mixed_expected, 'Plural inputs containing all do not select every language' );
	$gq_content = gq_enum_query( 'query($language: LanguageCodeFilterEnum!) { contentNodes(first: 100, where: { contentTypes: [PAGE], language: $language }) { nodes { databaseId } } }', array( 'language' => $gq_expected['all'] ) );
	gq_enum_same( array_column( $gq_content['contentNodes']['nodes'], 'databaseId' ), array( $gq_page_ids['all'] ), 'Reserved all slug filters heterogeneous content connections' );
}
$gq_sentinels = gq_enum_query( '{ all: pages(first: 100, where: { language: ALL }) { nodes { databaseId } } default: pages(first: 100, where: { language: DEFAULT }) { nodes { databaseId } } }' );
$gq_all_ids   = array_column( $gq_sentinels['all']['nodes'], 'databaseId' );
sort( $gq_all_ids );
$gq_wanted_ids = array_values( $gq_page_ids );
sort( $gq_wanted_ids );
gq_enum_same( $gq_all_ids, $gq_wanted_ids, 'ALL still selects every language' );
gq_enum_same( array_column( $gq_sentinels['default']['nodes'], 'databaseId' ), array( $gq_page_ids[ $gq_default ] ), 'DEFAULT still selects the configured default' );
WP_CLI::success( 'Language enum collisions and inputs passed: ' . $args[0] );
