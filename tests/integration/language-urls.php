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

/**
 * Resolves home URLs as aliases of one request and compares each result:
 * a page's database ID, 'post' for the posts index, or null.
 *
 * @param array<string,string>          $uris     URIs by alias.
 * @param array<string,int|string|null> $expected Expected results by alias.
 */
function gq_assert_homes( array $uris, array $expected ): void {
	$fields = '';
	foreach ( $uris as $alias => $uri ) {
		$fields .= $alias . ': nodeByUri(uri: ' . wp_json_encode( $uri ) . ') { __typename ... on Page { databaseId } ... on ContentType { name } } ';
	}
	$result = graphql( array( 'query' => '{ ' . $fields . '}' ) );
	foreach ( $expected as $alias => $value ) {
		$node   = $result['data'][ $alias ] ?? null;
		$actual = null === $node ? null : ( $node['databaseId'] ?? $node['name'] ?? $node['__typename'] );
		if ( ! empty( $result['errors'] ) || $actual !== $value ) {
			WP_CLI::error( 'Homes ' . wp_json_encode( $uris ) . ': expected ' . wp_json_encode( $expected ) . ', got ' . wp_json_encode( $result ) );
		}
	}
}

// A language without a translation of the static front page has no home
// node, never another language's front page, alone or beside aliases.
$gq_en_homes = array( 'en' => $gq_homes['en'] );
if ( 'query' === $gq_mode ) {
	$gq_en_homes['relative'] = '/?lang=en';
} elseif ( 'directory' === $gq_mode ) {
	$gq_en_homes['relative'] = '/en/';
}
$gq_missing = array_fill_keys( array_keys( $gq_en_homes ), null );
pll_save_post_translations( array( 'pt' => $gq_ids['inicio'] ) );
try {
	foreach ( $gq_en_homes as $gq_uri ) {
		gq_assert_url( $gq_uri, null );
	}
	gq_assert_homes( array( 'pt' => $gq_homes['pt'] ) + $gq_en_homes, array( 'pt' => $gq_ids['inicio'] ) + $gq_missing );
	gq_assert_homes( $gq_en_homes + array( 'pt' => $gq_homes['pt'] ), $gq_missing + array( 'pt' => $gq_ids['inicio'] ) );
} finally {
	pll_save_post_translations(
		array(
			'pt' => $gq_ids['inicio'],
			'en' => $gq_ids['home'],
		)
	);
}

// A translated front page the viewer cannot see stays hidden.
wp_update_post(
	array(
		'ID'          => $gq_ids['home'],
		'post_status' => 'draft',
	)
);
try {
	gq_assert_homes( array( 'pt' => $gq_homes['pt'] ) + $gq_en_homes, array( 'pt' => $gq_ids['inicio'] ) + $gq_missing );
} finally {
	wp_update_post(
		array(
			'ID'          => $gq_ids['home'],
			'post_status' => 'publish',
		)
	);
}

// Without a static front page, homes WPGraphQL resolves as its own root (the
// unprefixed one, a language's own host) stay the posts index. WPGraphQL has
// no root for a subdirectory install's home path.
if ( '' === $gq_base ) {
	$gq_index = array( 'pt' => $gq_homes['pt'] );
	if ( 'domain' === $gq_mode || 'subdomain' === $gq_mode ) {
		$gq_index['en'] = $gq_homes['en'];
	}
	update_option( 'show_on_front', 'posts' );
	try {
		gq_assert_homes( $gq_index, array_fill_keys( array_keys( $gq_index ), 'post' ) );
	} finally {
		update_option( 'show_on_front', 'page' );
	}
}
gq_assert_homes(
	array(
		'pt' => $gq_homes['pt'],
		'en' => $gq_homes['en'],
	),
	array(
		'pt' => $gq_ids['inicio'],
		'en' => $gq_ids['home'],
	)
);
WP_CLI::success( $gq_mode . ' ' . $args[2] . ' URL regressions passed.' );
