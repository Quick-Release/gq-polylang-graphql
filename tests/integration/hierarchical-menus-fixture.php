<?php
/**
 * Adds menu trees to the bilingual fixture, only on a disposable test database.
 *
 * @package GQ\PolylangGraphQL
 */

/**
 * Creates a published custom menu item with an explicit parent.
 *
 * @param int    $menu Menu ID.
 * @param string $label Item label.
 * @param int    $parent_id Parent item ID.
 * @return int
 */
function gq_menu_fixture_item( int $menu, string $label, int $parent_id = 0 ): int {
	$id = wp_update_nav_menu_item(
		$menu,
		0,
		array(
			'menu-item-title'     => $label,
			'menu-item-url'       => 'https://example.test/' . sanitize_title( $label ) . '/',
			'menu-item-type'      => 'custom',
			'menu-item-status'    => 'publish',
			'menu-item-parent-id' => $parent_id,
		)
	);
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( $id->get_error_message() );
	}
	return (int) $id;
}

$gq_tree_ids = array();
$gq_nav      = PLL()->options['nav_menus'];
$gq_theme    = get_option( 'stylesheet' );
foreach ( array( 'pt', 'en' ) as $gq_lang ) {
	$gq_menu      = (int) $gq_nav[ $gq_theme ]['primary'][ $gq_lang ];
	$gq_items     = wp_get_nav_menu_items( $gq_menu );
	$gq_root      = (int) $gq_items[0]->ID;
	$gq_child     = gq_menu_fixture_item( $gq_menu, strtoupper( $gq_lang ) . ' child', $gq_root );
	$gq_grand     = gq_menu_fixture_item( $gq_menu, strtoupper( $gq_lang ) . ' grandchild', $gq_child );
	$gq_other     = gq_menu_fixture_item( $gq_menu, strtoupper( $gq_lang ) . ' sibling' );
	$gq_cousin    = gq_menu_fixture_item( $gq_menu, strtoupper( $gq_lang ) . ' cousin', $gq_other );
	$gq_secondary = wp_create_nav_menu( 'Secondary ' . strtoupper( $gq_lang ) );
	if ( is_wp_error( $gq_secondary ) ) {
		WP_CLI::error( $gq_secondary->get_error_message() );
	}
	$gq_nav[ $gq_theme ]['secondary'][ $gq_lang ] = $gq_secondary;
	// Deliberately inconsistent data: parent points into another menu. The
	// nested connection must require BOTH the parent item and the parent menu.
	$gq_foreign              = gq_menu_fixture_item( $gq_secondary, strtoupper( $gq_lang ) . ' foreign', $gq_root );
	$gq_tree_ids[ $gq_lang ] = array(
		'menu'       => $gq_menu,
		'root'       => $gq_root,
		'child'      => $gq_child,
		'grandchild' => $gq_grand,
		'sibling'    => $gq_other,
		'cousin'     => $gq_cousin,
		'foreign'    => $gq_foreign,
	);
}

$gq_loose                   = wp_get_nav_menu_object( 'Menu solto' );
$gq_loose_items             = wp_get_nav_menu_items( $gq_loose->term_id );
$gq_loose_root              = (int) $gq_loose_items[0]->ID;
$gq_loose_child             = gq_menu_fixture_item( $gq_loose->term_id, 'Private child', $gq_loose_root );
$gq_loose_grand             = gq_menu_fixture_item( $gq_loose->term_id, 'Private grandchild', $gq_loose_child );
$gq_private_foreign         = gq_menu_fixture_item( $gq_loose->term_id, 'Private foreign', $gq_tree_ids['en']['root'] );
$gq_tree_ids['private']     = array(
	'menu'       => (int) $gq_loose->term_id,
	'root'       => $gq_loose_root,
	'child'      => $gq_loose_child,
	'grandchild' => $gq_loose_grand,
	'foreign'    => $gq_private_foreign,
);
PLL()->options['nav_menus'] = $gq_nav;
PLL()->options->save();
set_theme_mod(
	'nav_menu_locations',
	array(
		'primary'   => $gq_tree_ids['pt']['menu'],
		'secondary' => $gq_nav[ $gq_theme ]['secondary']['pt'],
	)
);
update_option( 'gq_hierarchical_menu_fixture', $gq_tree_ids );
WP_CLI::success( 'Hierarchical bilingual menus ready.' );
