<?php
/**
 * URL regressions on a disposable bilingual site. Run via run-language-urls.sh.
 *
 * @package GQ\PolylangGraphQL
 */

$gq_mode = $args[1];
$gq_base = 'subdirectory' === $args[2] ? '/blog' : '';
if ( 'configure' === $args[0] ) {
	update_option( 'home', 'http://urls.test' . $gq_base );
	update_option( 'siteurl', 'http://urls.test' . $gq_base );
	update_option( 'permalink_structure', 'query' === $gq_mode ? '' : '/%postname%/' );
	PLL()->options['force_lang']   = array(
		'directory' => 1,
		'subdomain' => 2,
		'domain'    => 3,
		'query'     => 0,
	)[ $gq_mode ];
	PLL()->options['hide_default'] = true;
	PLL()->options['domains']      = array(
		'pt' => 'http://urls.test' . $gq_base,
		'en' => 'http://english.test' . $gq_base,
	);
	PLL()->options->save();
	return;
}

/**
 * Resolves a URI with GraphQL and compares its database ID.
 *
 * @param string   $uri URI to resolve.
 * @param int|null $id Expected ID, or null.
 */
function gq_assert_url( string $uri, ?int $id ): void {
	$result = graphql(
		array(
			'query'     => 'query($uri: String!) { nodeByUri(uri: $uri) { ... on Page { databaseId } } }',
			'variables' => array( 'uri' => $uri ),
		)
	);
	if ( ! empty( $result['errors'] ) || ( $result['data']['nodeByUri']['databaseId'] ?? null ) !== $id ) {
		WP_CLI::error( $uri . ': expected ' . wp_json_encode( $id ) . ', got ' . wp_json_encode( $result ) );
	}
}

if ( 'ambiguous' === $args[0] ) {
	gq_assert_url( '/', null );
	gq_assert_url( 'http://urls.test' . $gq_base . '/', null );
	WP_CLI::success( 'Unqualified URLs rejected when the default is visible (' . $gq_mode . ').' );
	return;
}

$gq_ids = array();
foreach ( array( 'inicio', 'home', 'sobre', 'about' ) as $gq_slug ) {
	$gq_ids[ $gq_slug ] = (int) get_page_by_path( $gq_slug )->ID;
}
$gq_homes = array();
foreach ( array( 'pt', 'en' ) as $gq_lang ) {
	$gq_homes[ $gq_lang ] = \GQ\PolylangGraphQL\Languages::home_url( $gq_lang );
	gq_assert_url( $gq_homes[ $gq_lang ], $gq_ids[ 'pt' === $gq_lang ? 'inicio' : 'home' ] );
}

if ( 'query' === $gq_mode ) {
	gq_assert_url( 'http://urls.test' . $gq_base . '/?page_id=' . $gq_ids['about'] . '&lang=en', $gq_ids['about'] );
	gq_assert_url( 'http://urls.test' . $gq_base . '/?lang=en&page_id=' . $gq_ids['sobre'], null );
	gq_assert_url( '/?lang=en&page_id=' . $gq_ids['about'], $gq_ids['about'] );
	gq_assert_url( '/?lang=unknown', null );
	gq_assert_url( '/?lang[]=en', null );
	gq_assert_url( '/?lang=en&lang=pt', null );
	gq_assert_url( '/?lang=en', $gq_ids['home'] );
	gq_assert_url( '/?page_id=' . $gq_ids['about'], null );
	gq_assert_url( '/?lang=en#comment-999999', null );
	// A relative query-mode URI without lang does not identify a language.
	gq_assert_url( '/', null );
} else {
	gq_assert_url( $gq_homes['en'] . 'about/', $gq_ids['about'] );
	gq_assert_url( $gq_homes['pt'] . 'sobre/', $gq_ids['sobre'] );
	gq_assert_url( $gq_homes['en'] . 'sobre/', null );
	gq_assert_url( $gq_homes['pt'] . 'about/', null );
	if ( 'directory' === $gq_mode ) {
		gq_assert_url( '/en/', $gq_ids['home'] );
		gq_assert_url( '/en/about/', $gq_ids['about'] );
		gq_assert_url( '/en/sobre/', null );
		gq_assert_url( $gq_base . '/en/about/', $gq_ids['about'] );
	} else {
		gq_assert_url( '/', null );
		gq_assert_url( '/about/', null );
		gq_assert_url( '/sobre/', null );
	}
}
gq_assert_url( 'http://unrelated.test' . $gq_base . '/', null );
if ( '' !== $gq_base ) {
	gq_assert_url( 'http://urls.test/blogger/', null );
}
// The resolver scope must remain correct for content as well as home aliases.
$gq_about_url = 'query' === $gq_mode ? $gq_homes['en'] . '&page_id=' . $gq_ids['about'] : $gq_homes['en'] . 'about/';
$gq_wrong_url = 'query' === $gq_mode ? $gq_homes['en'] . '&page_id=' . $gq_ids['sobre'] : $gq_homes['en'] . 'sobre/';
$gq_aliases   = graphql(
	array(
		'query' => '{ valid: nodeByUri(uri: ' . wp_json_encode( $gq_about_url ) . ') { ... on Page { databaseId } } wrong: nodeByUri(uri: ' . wp_json_encode( $gq_wrong_url ) . ') { ... on Page { databaseId } } }',
	)
);
if ( ! empty( $gq_aliases['errors'] ) || $gq_aliases['data']['valid']['databaseId'] !== $gq_ids['about'] || null !== $gq_aliases['data']['wrong'] ) {
	WP_CLI::error( 'Aliased content URLs: ' . wp_json_encode( $gq_aliases ) );
}
// Multiple aliases in one execution must retain each URL's language independently.
$gq_result = graphql(
	array(
		'query' => '{ pt: nodeByUri(uri: ' . wp_json_encode( $gq_homes['pt'] ) . ') { ... on Page { databaseId } } en: nodeByUri(uri: ' . wp_json_encode( $gq_homes['en'] ) . ') { ... on Page { databaseId } } }',
	)
);
if ( ! empty( $gq_result['errors'] ) || $gq_result['data']['pt']['databaseId'] !== $gq_ids['inicio'] || $gq_result['data']['en']['databaseId'] !== $gq_ids['home'] ) {
	WP_CLI::error( 'Aliased language homes: ' . wp_json_encode( $gq_result ) );
}
WP_CLI::success( $gq_mode . ' ' . $args[2] . ' URL regressions passed.' );
