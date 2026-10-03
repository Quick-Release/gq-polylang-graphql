<?php
/**
 * A bilingual site for the integration tests, built through Polylang's own
 * API (run with `wp eval-file` on a fresh install):
 *
 * - Portuguese (pt, pt_PT_ao90), the default, without a URL prefix, and
 *   English (en, en_US) under /en/;
 * - a static front page in both (Início, Home), a page in both (sobre,
 *   about), a page in each language only, and a draft English translation;
 * - a category in both (noticias, news) with a post in each;
 * - a menu per language at the `primary` location, and one at none;
 * - the site title and tagline translated into English.
 *
 * @package GQ\PolylangGraphQL
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited

foreach ( get_posts(
	array(
		'post_type'   => 'any',
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
	)
) as $gq_id ) {
	wp_delete_post( $gq_id, true );
}

/**
 * Fails the fixture with a message.
 *
 * @param string $message What went wrong.
 */
function gq_fixture_fail( string $message ): void {
	WP_CLI::error( $message );
}

foreach (
	array(
		array(
			'locale'     => 'pt_PT_ao90',
			'slug'       => 'pt',
			'name'       => 'Português',
			'term_group' => 0,
		),
		array(
			'locale'     => 'en_US',
			'slug'       => 'en',
			'name'       => 'English',
			'term_group' => 1,
		),
	) as $gq_language
) {
	$gq_added = PLL()->model->languages->add( $gq_language );
	if ( is_wp_error( $gq_added ) ) {
		gq_fixture_fail( $gq_added->get_error_message() );
	}
}
PLL()->model->clean_languages_cache();

// Language as a directory, the default language's hidden, no /language/.
PLL()->options['force_lang']   = 1;
PLL()->options['hide_default'] = true;
PLL()->options['rewrite']      = true;
PLL()->options['default_lang'] = 'pt';
PLL()->options->save();

/**
 * Creates a published (or `$status`) page in a language.
 *
 * @param string $title  The title.
 * @param string $slug   The slug.
 * @param string $lang   The language slug.
 * @param string $status The post status.
 */
function gq_fixture_page( string $title, string $slug, string $lang, string $status = 'publish' ): int {
	$id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_status'  => $status,
			'post_content' => "<p>{$title}</p>",
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		gq_fixture_fail( $id->get_error_message() );
	}
	pll_set_post_language( $id, $lang );
	return $id;
}

$gq_inicio = gq_fixture_page( 'Início', 'inicio', 'pt' );
$gq_home   = gq_fixture_page( 'Home', 'home', 'en' );
pll_save_post_translations(
	array(
		'pt' => $gq_inicio,
		'en' => $gq_home,
	)
);

$gq_sobre = gq_fixture_page( 'Sobre', 'sobre', 'pt' );
$gq_about = gq_fixture_page( 'About', 'about', 'en' );
pll_save_post_translations(
	array(
		'pt' => $gq_sobre,
		'en' => $gq_about,
	)
);

gq_fixture_page( 'Só em português', 'so-em-portugues', 'pt' );
gq_fixture_page( 'English only', 'english-only', 'en' );

$gq_contactos = gq_fixture_page( 'Contactos', 'contactos', 'pt' );
$gq_contact   = gq_fixture_page( 'Contact', 'contact', 'en', 'draft' );
pll_save_post_translations(
	array(
		'pt' => $gq_contactos,
		'en' => $gq_contact,
	)
);

update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $gq_inicio );

$gq_noticias = wp_insert_term( 'Notícias', 'category', array( 'slug' => 'noticias' ) );
$gq_news     = wp_insert_term( 'News', 'category', array( 'slug' => 'news' ) );
if ( is_wp_error( $gq_noticias ) || is_wp_error( $gq_news ) ) {
	gq_fixture_fail( 'Could not create the categories.' );
}
pll_set_term_language( $gq_noticias['term_id'], 'pt' );
pll_set_term_language( $gq_news['term_id'], 'en' );
pll_save_term_translations(
	array(
		'pt' => $gq_noticias['term_id'],
		'en' => $gq_news['term_id'],
	)
);

foreach ( array(
	'pt' => array( 'Artigo', 'artigo', $gq_noticias ),
	'en' => array( 'Article', 'article', $gq_news ),
) as $gq_lang => $gq_post ) {
	$gq_post_id = wp_insert_post(
		array(
			'post_title'    => $gq_post[0],
			'post_name'     => $gq_post[1],
			'post_status'   => 'publish',
			'post_category' => array( $gq_post[2]['term_id'] ),
		),
		true
	);
	if ( is_wp_error( $gq_post_id ) ) {
		gq_fixture_fail( $gq_post_id->get_error_message() );
	}
	pll_set_post_language( $gq_post_id, $gq_lang );
}

$gq_menus = array();
foreach ( array(
	'pt' => array( 'Menu PT', $gq_sobre ),
	'en' => array( 'Menu EN', $gq_about ),
) as $gq_lang => $gq_menu ) {
	$gq_menu_id = wp_create_nav_menu( $gq_menu[0] );
	if ( is_wp_error( $gq_menu_id ) ) {
		gq_fixture_fail( $gq_menu_id->get_error_message() );
	}
	wp_update_nav_menu_item(
		$gq_menu_id,
		0,
		array(
			'menu-item-object-id' => $gq_menu[1],
			'menu-item-object'    => 'page',
			'menu-item-type'      => 'post_type',
			'menu-item-status'    => 'publish',
		)
	);
	$gq_menus[ $gq_lang ] = $gq_menu_id;
}
// A menu at no location: its items stay private.
$gq_loose = wp_create_nav_menu( 'Menu solto' );
if ( is_wp_error( $gq_loose ) ) {
	gq_fixture_fail( $gq_loose->get_error_message() );
}
wp_update_nav_menu_item(
	$gq_loose,
	0,
	array(
		'menu-item-title'  => 'Solto',
		'menu-item-url'    => 'https://example.test/',
		'menu-item-type'   => 'custom',
		'menu-item-status' => 'publish',
	)
);
set_theme_mod( 'nav_menu_locations', array( 'primary' => $gq_menus['pt'] ) );
$gq_nav_menus                               = PLL()->options['nav_menus'];
$gq_nav_menus                               = is_array( $gq_nav_menus ) ? $gq_nav_menus : array();
$gq_nav_menus[ get_option( 'stylesheet' ) ] = array( 'primary' => $gq_menus );
PLL()->options['nav_menus']                 = $gq_nav_menus;
PLL()->options->save();

update_option( 'blogdescription', 'Uma descrição' );
$gq_en = PLL()->model->get_language( 'en' );
$gq_mo = new PLL_MO();
$gq_mo->import_from_db( $gq_en );
$gq_mo->add_entry( $gq_mo->make_entry( 'Site de teste', 'Test site' ) );
$gq_mo->add_entry( $gq_mo->make_entry( 'Uma descrição', 'A tagline' ) );
$gq_mo->export_to_db( $gq_en );

WP_CLI::success( 'Bilingual fixture ready.' );
