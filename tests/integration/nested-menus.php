<?php
/**
 * Nested menu scope and authorization assertions against installed WPGraphQL.
 *
 * @package GQ\PolylangGraphQL
 */

/**
 * Runs a GraphQL query, failing on schema or execution errors.
 *
 * @param string $query GraphQL document.
 * @return array<string,mixed>
 */
function gq_menu_query( string $query ): array {
	$result = graphql( array( 'query' => $query ) );
	if ( ! empty( $result['errors'] ) ) {
		WP_CLI::error( wp_json_encode( $result ) );
	}
	return $result['data'];
}

/**
 * Compares values with a useful failure message.
 *
 * @param mixed  $actual Actual value.
 * @param mixed  $expected Expected value.
 * @param string $message Assertion label.
 */
function gq_menu_same( $actual, $expected, string $message ): void {
	if ( $actual !== $expected ) {
		WP_CLI::error( $message . ': expected ' . wp_json_encode( $expected ) . ', got ' . wp_json_encode( $actual ) );
	}
}

/**
 * The expected three-level tree for one root.
 *
 * @param array<string,int> $ids Fixture IDs.
 * @return array<string,mixed>
 */
function gq_menu_tree( array $ids ): array {
	return array(
		'databaseId' => $ids['root'],
		'childItems' => array(
			'nodes' => array(
				array(
					'databaseId' => $ids['child'],
					'childItems' => array(
						'nodes' => array( array( 'databaseId' => $ids['grandchild'] ) ),
					),
				),
			),
		),
	);
}

$gq_ids   = get_option( 'gq_hierarchical_menu_fixture' );
$gq_admin = 'administrator' === $args[0];
wp_set_current_user( $gq_admin ? (int) get_user_by( 'login', 'admin' )->ID : ( 'subscriber' === $args[0] ? (int) get_user_by( 'login', 'reader' )->ID : 0 ) );
$gq_fields = 'databaseId childItems(first: 20) { nodes { databaseId childItems(first: 20) { nodes { databaseId } } } }';
foreach ( array(
	'pt' => 'PT',
	'en' => 'EN',
) as $gq_lang => $gq_code ) {
	$gq_data     = gq_menu_query( '{ menuItems(first: 20, where: { location: PRIMARY, language: ' . $gq_code . ', parentDatabaseId: 0 }) { nodes { ' . $gq_fields . ' } } }' );
	$gq_expected = array(
		gq_menu_tree( $gq_ids[ $gq_lang ] ),
		array(
			'databaseId' => $gq_ids[ $gq_lang ]['sibling'],
			'childItems' => array(
				'nodes' => array(
					array(
						'databaseId' => $gq_ids[ $gq_lang ]['cousin'],
						'childItems' => array( 'nodes' => array() ),
					),
				),
			),
		),
	);
	gq_menu_same( $gq_data['menuItems']['nodes'], $gq_expected, $gq_code . ' descendants inherit menu and parent scope' );
}

$gq_default = gq_menu_query( '{ menuItems(first: 20, where: { location: PRIMARY, parentDatabaseId: 0 }) { nodes { ' . $gq_fields . ' } } }' );
gq_menu_same( $gq_default['menuItems']['nodes'][0], gq_menu_tree( $gq_ids['pt'] ), 'Default-language descendants' );

$gq_root = $gq_ids['en']['root'];
foreach (
	array(
		'language: EN'                                  => array( $gq_ids['en']['child'] ),
		'language: PT'                                  => array(),
		'location: PRIMARY'                             => array( $gq_ids['en']['child'] ),
		'location: PRIMARY, language: EN'               => array( $gq_ids['en']['child'] ),
		'location: PRIMARY, language: PT'               => array(),
		'location: SECONDARY'                           => array(),
		'location: SECONDARY, language: EN'             => array(),
		'parentDatabaseId: ' . $gq_ids['en']['sibling'] => array( $gq_ids['en']['child'] ),
		'parentId: "' . $gq_ids['en']['sibling'] . '"'  => array( $gq_ids['en']['child'] ),
	) as $gq_where => $gq_expected
) {
	$gq_data = gq_menu_query( '{ menuItem(id: "' . $gq_root . '", idType: DATABASE_ID) { childItems(first: 20, where: { ' . $gq_where . ' }) { nodes { databaseId } } } }' );
	gq_menu_same( array_column( $gq_data['menuItem']['childItems']['nodes'], 'databaseId' ), $gq_expected, 'Explicit child arguments cannot broaden parent scope: ' . $gq_where );
}

// Menu.menuItems uses the same resolver, but its registration overwrites the
// taxonomy query after construction. Explicit conflicts must still stay empty.
foreach ( array( 'pt', 'en' ) as $gq_lang ) {
	$gq_menu = gq_menu_query( '{ menu(id: "' . $gq_ids[ $gq_lang ]['menu'] . '", idType: DATABASE_ID) { menuItems(first: 20, where: { parentDatabaseId: 0 }) { nodes { ' . $gq_fields . ' } } } }' );
	gq_menu_same( $gq_menu['menu']['menuItems']['nodes'][0], gq_menu_tree( $gq_ids[ $gq_lang ] ), 'Menu source scope: ' . $gq_lang );
}
foreach ( array( 'language: PT', 'location: SECONDARY', 'language: EN, location: SECONDARY' ) as $gq_where ) {
	$gq_menu = gq_menu_query( '{ menu(id: "' . $gq_ids['en']['menu'] . '", idType: DATABASE_ID) { menuItems(first: 20, where: { ' . $gq_where . ' }) { nodes { databaseId } } } }' );
	gq_menu_same( $gq_menu['menu']['menuItems']['nodes'], array(), 'Menu source explicit conflict: ' . $gq_where );
}
$gq_private_menu = gq_menu_query( '{ menu(id: "' . $gq_ids['private']['menu'] . '", idType: DATABASE_ID) { menuItems(first: 20, where: { parentDatabaseId: 0 }) { nodes { ' . $gq_fields . ' } } } }' );
gq_menu_same( $gq_private_menu['menu'], $gq_admin ? array( 'menuItems' => array( 'nodes' => array( gq_menu_tree( $gq_ids['private'] ) ) ) ) : null, 'Unassigned menu source authorization' );

// Mixed language aliases in one request must not leak inherited scope.
$gq_aliases = gq_menu_query( '{ en: menuItem(id: "' . $gq_ids['en']['root'] . '", idType: DATABASE_ID) { ' . $gq_fields . ' } pt: menuItem(id: "' . $gq_ids['pt']['root'] . '", idType: DATABASE_ID) { ' . $gq_fields . ' } }' );
gq_menu_same( $gq_aliases['en'], gq_menu_tree( $gq_ids['en'] ), 'English alias' );
gq_menu_same( $gq_aliases['pt'], gq_menu_tree( $gq_ids['pt'] ), 'Portuguese alias' );

foreach ( array( 'root', 'child', 'grandchild', 'foreign' ) as $gq_key ) {
	$gq_private = gq_menu_query( '{ menuItem(id: "' . $gq_ids['private'][ $gq_key ] . '", idType: DATABASE_ID) { ' . $gq_fields . ' } }' );
	if ( $gq_admin ) {
		gq_menu_same( $gq_private['menuItem']['databaseId'], $gq_ids['private'][ $gq_key ], 'Administrator retains private menu access' );
		if ( 'root' === $gq_key ) {
			gq_menu_same( $gq_private['menuItem'], gq_menu_tree( $gq_ids['private'] ), 'Authorized private descendants' );
		}
	} else {
		gq_menu_same( $gq_private['menuItem'], null, 'Unassigned menu item remains private: ' . $gq_key );
	}
}
$gq_all        = gq_menu_query( '{ menuItems(first: 100) { nodes { databaseId } } }' );
$gq_public_ids = array_column( $gq_all['menuItems']['nodes'], 'databaseId' );
if ( ! $gq_admin && array_intersect( $gq_public_ids, array_values( array_diff_key( $gq_ids['private'], array( 'menu' => true ) ) ) ) ) {
	WP_CLI::error( 'Unassigned items leaked through the root connection.' );
}
WP_CLI::success( 'Nested menu scope and privacy passed: ' . $args[0] );
