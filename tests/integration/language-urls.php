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

/**
 * Resolves URIs as aliases of one request and compares each result: a
 * comment's or page's database ID, or null.
 *
 * @param array<string,string>   $uris     URIs by alias.
 * @param array<string,int|null> $expected Expected results by alias.
 */
function gq_assert_nodes( array $uris, array $expected ): void {
	$fields = '';
	foreach ( $uris as $alias => $uri ) {
		$fields .= $alias . ': nodeByUri(uri: ' . wp_json_encode( $uri ) . ') { __typename ... on Comment { databaseId } ... on Page { databaseId } } ';
	}
	$result = graphql( array( 'query' => '{ ' . $fields . '}' ) );
	foreach ( $expected as $alias => $value ) {
		if ( ! empty( $result['errors'] ) || ( $result['data'][ $alias ]['databaseId'] ?? null ) !== $value ) {
			WP_CLI::error( 'Nodes ' . wp_json_encode( $uris ) . ': expected ' . wp_json_encode( $expected ) . ', got ' . wp_json_encode( $result ) );
		}
	}
}

/**
 * A page's URL in a language: its path, or its page_id in query mode.
 *
 * @param string $home The language's home URL.
 * @param string $slug The page's slug.
 * @param string $mode The URL mode.
 */
function gq_page_url( string $home, string $slug, string $mode ): string {
	if ( 'query' !== $mode ) {
		return $home . $slug . '/';
	}
	return $home . ( false === strpos( $home, '?' ) ? '?' : '&' ) . 'page_id=' . get_page_by_path( $slug )->ID;
}

// NodeResolver answers #comment-ID before graphql_resolve_uri; a comment URL
// still resolves only in its post's language, and only for those who may see it.
$gq_lang_less = wp_insert_post(
	array(
		'post_type'   => 'page',
		'post_title'  => 'Sem idioma',
		'post_name'   => 'sem-idioma',
		'post_status' => 'publish',
	)
);
wp_delete_object_term_relationships( $gq_lang_less, 'language' );
clean_post_cache( $gq_lang_less );
$gq_comments = array();
foreach (
	array(
		'about'     => array( 'about', 1 ),
		'sobre'     => array( 'sobre', 1 ),
		'held'      => array( 'about', 0 ),
		'draft'     => array( 'contact', 1 ),
		'lang_less' => array( 'sem-idioma', 1 ),
	) as $gq_key => list( $gq_slug, $gq_approved )
) {
	$gq_comments[ $gq_key ] = wp_insert_comment(
		array(
			'comment_post_ID'  => get_page_by_path( $gq_slug )->ID,
			'comment_content'  => 'Comment ' . $gq_key,
			'comment_author'   => 'Visitor',
			'comment_approved' => $gq_approved,
		)
	);
}
try {
	if ( false !== pll_get_post_language( $gq_lang_less ) ) {
		WP_CLI::error( 'The language-less page has a language.' );
	}
	$gq_en_about  = gq_page_url( $gq_homes['en'], 'about', $gq_mode );
	$gq_pt_sobre  = gq_page_url( $gq_homes['pt'], 'sobre', $gq_mode );
	$gq_urls      = array(
		'en_valid'   => $gq_en_about . '#comment-' . $gq_comments['about'],
		'en_wrong'   => $gq_en_about . '#comment-' . $gq_comments['sobre'],
		'pt_valid'   => $gq_pt_sobre . '#comment-' . $gq_comments['sobre'],
		'pt_wrong'   => $gq_pt_sobre . '#comment-' . $gq_comments['about'],
		'en_page'    => $gq_en_about,
		'pt_page'    => $gq_pt_sobre,
		'missing'    => $gq_en_about . '#comment-999999',
		'held'       => $gq_en_about . '#comment-' . $gq_comments['held'],
		'draft'      => gq_page_url( $gq_homes['en'], 'contact', $gq_mode ) . '#comment-' . $gq_comments['draft'],
		'en_no_lang' => $gq_en_about . '#comment-' . $gq_comments['lang_less'],
		'pt_no_lang' => $gq_pt_sobre . '#comment-' . $gq_comments['lang_less'],
		'en_home'    => $gq_homes['en'] . '#comment-' . $gq_comments['about'],
		'pt_home'    => $gq_homes['pt'] . '#comment-' . $gq_comments['about'],
	);
	$gq_anonymous = array(
		'en_valid'   => $gq_comments['about'],
		'en_wrong'   => null,
		'pt_valid'   => $gq_comments['sobre'],
		'pt_wrong'   => null,
		'en_page'    => $gq_ids['about'],
		'pt_page'    => $gq_ids['sobre'],
		'missing'    => null,
		'held'       => null,
		'draft'      => null,
		'en_no_lang' => $gq_comments['lang_less'],
		'pt_no_lang' => $gq_comments['lang_less'],
		'en_home'    => $gq_comments['about'],
		'pt_home'    => null,
	);
	if ( 'directory' === $gq_mode ) {
		$gq_urls['relative_valid']      = '/en/about/#comment-' . $gq_comments['about'];
		$gq_urls['relative_wrong']      = '/en/about/#comment-' . $gq_comments['sobre'];
		$gq_anonymous['relative_valid'] = $gq_comments['about'];
		$gq_anonymous['relative_wrong'] = null;
	} elseif ( 'query' !== $gq_mode ) {
		// Hostless paths name no language on separate hosts.
		$gq_urls['hostless']      = '/about/#comment-' . $gq_comments['about'];
		$gq_anonymous['hostless'] = null;
	}
	foreach ( $gq_urls as $gq_alias => $gq_uri ) {
		gq_assert_nodes( array( $gq_alias => $gq_uri ), array( $gq_alias => $gq_anonymous[ $gq_alias ] ) );
	}
	// Aliases in either order must keep each URL's language independently.
	gq_assert_nodes( $gq_urls, $gq_anonymous );
	gq_assert_nodes( array_reverse( $gq_urls, true ), $gq_anonymous );

	// A moderator sees held comments, still only in their post's language.
	wp_set_current_user( (int) get_user_by( 'login', 'admin' )->ID );
	try {
		gq_assert_nodes(
			array(
				'held_wrong' => $gq_pt_sobre . '#comment-' . $gq_comments['held'],
				'held'       => $gq_urls['held'],
				'draft'      => $gq_urls['draft'],
			),
			array(
				'held_wrong' => null,
				'held'       => $gq_comments['held'],
				'draft'      => $gq_comments['draft'],
			)
		);
	} finally {
		wp_set_current_user( 0 );
	}
} finally {
	foreach ( $gq_comments as $gq_comment ) {
		wp_delete_comment( $gq_comment, true );
	}
	wp_delete_post( $gq_lang_less, true );
}

/**
 * A URL with only its host transformed.
 *
 * @param string   $url       Absolute URL.
 * @param callable $transform Host transformation.
 */
function gq_with_host( string $url, callable $transform ): string {
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	return (string) preg_replace( '#^(\w+://)' . preg_quote( $host, '#' ) . '#', '${1}' . $transform( $host ), $url, 1 );
}

/**
 * A host with each label capitalized: English.Test.
 *
 * @param string $host Host.
 */
function gq_mixed_case( string $host ): string {
	return implode( '.', array_map( 'ucfirst', explode( '.', $host ) ) );
}

/**
 * A URL with one more lang selector.
 *
 * @param string $url  URL.
 * @param string $lang Language selector.
 */
function gq_with_lang( string $url, string $lang ): string {
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . 'lang=' . $lang;
}

// Hosts compare case-insensitively, in every mode and on every language's
// host; paths, query parameters, and language slugs stay case-sensitive.
foreach ( array( 'strtolower', 'strtoupper', 'gq_mixed_case' ) as $gq_case ) {
	$gq_en = gq_with_host( $gq_homes['en'], $gq_case );
	$gq_pt = gq_with_host( $gq_homes['pt'], $gq_case );
	gq_assert_homes(
		array(
			'pt' => $gq_pt,
			'en' => $gq_en,
		),
		array(
			'pt' => $gq_ids['inicio'],
			'en' => $gq_ids['home'],
		)
	);
	gq_assert_url( gq_page_url( $gq_en, 'about', $gq_mode ), $gq_ids['about'] );
	gq_assert_url( gq_page_url( $gq_pt, 'sobre', $gq_mode ), $gq_ids['sobre'] );
	gq_assert_url( gq_page_url( $gq_en, 'sobre', $gq_mode ), null );
	gq_assert_url( gq_page_url( $gq_pt, 'about', $gq_mode ), null );
	// Conflicting or differently cased language selectors.
	gq_assert_url( gq_with_lang( gq_page_url( $gq_en, 'about', $gq_mode ), 'pt' ), null );
	gq_assert_url( gq_with_lang( gq_page_url( $gq_pt, 'sobre', $gq_mode ), 'en' ), null );
	if ( 'query' !== $gq_mode ) {
		gq_assert_url( gq_with_lang( gq_page_url( $gq_en, 'about', $gq_mode ), 'en' ), $gq_ids['about'] );
		gq_assert_url( gq_with_lang( gq_page_url( $gq_en, 'about', $gq_mode ), 'EN' ), null );
	} else {
		gq_assert_url( str_replace( 'lang=en', 'lang=EN', gq_page_url( $gq_en, 'about', $gq_mode ) ), null );
	}
	// Unknown hosts, a cased subdirectory, userinfo, and unsupported schemes.
	foreach ( array( 'http://unrelated.test', 'http://english.test.unrelated', 'http://urls.test.unrelated', 'http://en.unrelated.test' ) as $gq_unknown ) {
		gq_assert_url( gq_with_host( $gq_unknown . $gq_base . '/', $gq_case ), null );
	}
	if ( '' !== $gq_base ) {
		gq_assert_url( str_replace( $gq_base . '/', strtoupper( $gq_base ) . '/', $gq_en ), null );
	}
	gq_assert_url( str_replace( '://', '://visitor@', $gq_en ), null );
	gq_assert_url( str_replace( 'http://', 'ftp://', $gq_en ), null );
	gq_assert_url( str_replace( 'http://', 'HTTP://', $gq_en ), $gq_ids['home'] );
}
WP_CLI::success( $gq_mode . ' ' . $args[2] . ' URL regressions passed.' );
