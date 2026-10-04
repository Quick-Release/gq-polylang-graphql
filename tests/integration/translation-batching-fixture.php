<?php
/**
 * Translation batching fixtures, only for the isolated test database.
 *
 * @package GQ\PolylangGraphQL
 */

// WP-CLI eval-file executes these variables in its own scope.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited

foreach ( array(
	'en' => 'en_US',
	'fr' => 'fr_FR',
	'de' => 'de_DE',
) as $slug => $locale ) {
	$result = PLL()->model->languages->add(
		array(
			'slug'   => $slug,
			'locale' => $locale,
			'name'   => $slug,
		)
	);
	if ( is_wp_error( $result ) ) {
		WP_CLI::error( $result->get_error_message() );
	}
}
PLL()->model->clean_languages_cache();
PLL()->options['default_lang'] = 'en';
PLL()->options->save();

$fixture = array(
	'posts'     => array(),
	'terms'     => array(),
	'all_posts' => array(),
);
foreach ( range( 1, 20 ) as $index ) {
	$translations = array();
	foreach ( array( 'en', 'fr', 'de' ) as $slug ) {
		$status = 'fr' === $slug ? array( 'publish', 'draft', 'private' )[ $index % 3 ] : 'publish';
		$id     = wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_title'  => "Batch $index $slug",
				'post_status' => $status,
				'post_author' => 1,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $id->get_error_message() );
		}
		pll_set_post_language( $id, $slug );
		$translations[ $slug ]  = $id;
		$fixture['all_posts'][] = $id;
	}
	pll_save_post_translations( $translations );
	$fixture['posts'][] = $translations;
}
// A node with no translations must still return an empty list.
$id = wp_insert_post(
	array(
		'post_type'   => 'post',
		'post_title'  => 'Alone',
		'post_status' => 'publish',
	)
);
pll_set_post_language( $id, 'en' );
$fixture['posts'][]     = array( 'en' => $id );
$fixture['all_posts'][] = $id;

foreach ( range( 1, 10 ) as $index ) {
	$translations = array();
	foreach ( array( 'en', 'fr', 'de' ) as $slug ) {
		$term = wp_insert_term( "Batch $index $slug", 'category' );
		if ( is_wp_error( $term ) ) {
			WP_CLI::error( $term->get_error_message() );
		}
		pll_set_term_language( $term['term_id'], $slug );
		$translations[ $slug ] = $term['term_id'];
	}
	pll_save_term_translations( $translations );
	$fixture['terms'][] = $translations;
}
update_option( 'gq_translation_batching_fixture', $fixture );
