<?php
/**
 * Valid Polylang slugs that collide after GraphQL enum normalization.
 * Only run via run-language-enums.sh against its disposable database.
 *
 * @package GQ\PolylangGraphQL
 */

/**
 * Adds a language and a published page through public WordPress/Polylang APIs.
 *
 * @param string $slug Language slug.
 * @param int    $order Language order.
 */
function gq_enum_fixture_language( string $slug, int $order ): void {
	$language = PLL()->model->languages->add(
		array(
			'slug'       => $slug,
			'locale'     => 'en_US',
			'name'       => 'Language ' . $slug,
			'term_group' => $order,
		)
	);
	if ( is_wp_error( $language ) ) {
		WP_CLI::error( $slug . ': ' . $language->get_error_message() );
	}
	$page = wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Page ' . $slug,
			'post_name'   => 'page-' . $slug,
		),
		true
	);
	if ( is_wp_error( $page ) ) {
		WP_CLI::error( $page->get_error_message() );
	}
	pll_set_post_language( $page, $slug );
	$ids          = get_option( 'gq_language_enum_pages', array() );
	$ids[ $slug ] = (int) $page;
	update_option( 'gq_language_enum_pages', $ids );
}

if ( 'basic' === $args[0] ) {
	foreach ( get_posts(
		array(
			'post_type'   => 'any',
			'post_status' => 'any',
			'numberposts' => -1,
		)
	) as $gq_post ) {
		wp_delete_post( $gq_post->ID, true );
	}
	gq_enum_fixture_language( 'en', 0 );
	gq_enum_fixture_language( 'pt-br', 1 );
	PLL()->options['default_lang'] = 'en';
	PLL()->options->save();
} elseif ( 'collisions' === $args[0] ) {
	// Call the mapper before changing the language set to catch stale caches.
	if ( 'PT_BR' !== \GQ\PolylangGraphQL\Languages::code( 'pt-br' ) ) {
		WP_CLI::error( 'The nonconflicting pt-br name changed.' );
	}
	foreach ( array( 'pt_br', 'all', 'default', 'pt_br__70742d6272', 'pt_br__70742d6272_', 'all__616c6c' ) as $gq_order => $gq_slug ) {
		gq_enum_fixture_language( $gq_slug, $gq_order + 2 );
	}
	if ( 'PT_BR__70742D6272__' !== \GQ\PolylangGraphQL\Languages::code( 'pt-br' ) ) {
		WP_CLI::error( 'Collision mapping did not refresh or failed secondary collision handling.' );
	}
} elseif ( 'reorder' === $args[0] ) {
	foreach ( array_reverse( \GQ\PolylangGraphQL\Languages::all() ) as $gq_order => $gq_language ) {
		$gq_updated = PLL()->model->languages->update(
			array(
				'lang_id'    => $gq_language->term_id,
				// Polylang 3.7 validates the full language on update.
				'slug'       => $gq_language->slug,
				'locale'     => $gq_language->locale,
				'name'       => $gq_language->name,
				'term_group' => $gq_order,
			)
		);
		if ( is_wp_error( $gq_updated ) ) {
			WP_CLI::error( $gq_updated->get_error_message() );
		}
	}
} elseif ( 'reserved-default' === $args[0] ) {
	PLL()->options['default_lang'] = 'all';
	PLL()->options->save();
}
WP_CLI::success( 'Enum fixture: ' . $args[0] );
