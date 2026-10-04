<?php
/**
 * Correctness and cold-cache query-count regression, invoked by WP-CLI.
 *
 * @package GQ\PolylangGraphQL
 */

// WP-CLI eval-file executes these variables in its own scope.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited

/** Counts real post loader batches without replacing visibility checks. */
class GQ_Batching_Post_Loader extends \WPGraphQL\Data\Loader\PostObjectLoader {
	/**
	 * Loaded key batches.
	 *
	 * @var array<int,array<int,int|string>>
	 */
	public static $batches = array();
	/**
	 * SQL queries executed inside the loader.
	 *
	 * @var int
	 */
	public static $queries = 0;

	/**
	 * Loads keys using the unmodified WPGraphQL implementation.
	 *
	 * @param array<int,int|string> $keys Keys to load.
	 * @return array<int|string,\WP_Post|null>
	 */
	public function loadKeys( array $keys ) {
		global $wpdb;
		self::$batches[] = $keys;
		$before          = $wpdb->num_queries;
		$result          = parent::loadKeys( $keys );
		self::$queries  += $wpdb->num_queries - $before;
		return $result;
	}
}

/**
 * Fails the WP-CLI process on a regression.
 *
 * @param bool   $condition Expected to be true.
 * @param string $message Failure description.
 */
function gq_batch_assert( $condition, $message ) {
	if ( ! $condition ) {
		WP_CLI::error( $message );
	}
}

$baseline = in_array( 'baseline', $args, true );
$admin    = in_array( 'admin', $args, true );
wp_set_current_user( $admin ? 1 : 0 );
$fixture = get_option( 'gq_translation_batching_fixture' );
foreach ( $fixture['all_posts'] as $id ) {
	clean_post_cache( $id );
}
add_filter(
	'graphql_data_loaders',
	static function ( $loaders, $context ) {
		$loaders['post'] = new GQ_Batching_Post_Loader( $context );
		return $loaders;
	},
	10,
	2
);

// Restrict the connection to fixture parents; do not depend on site defaults.
$ids   = implode( ',', array_column( $fixture['posts'], 'en' ) );
$query = '{ posts(first: 100, where: {in: [' . $ids . '], orderby: {field: IN, order: ASC}}) { nodes {
 databaseId translations { databaseId } again: translations { databaseId }
 translation(language: FR) { databaseId }
} } }';
global $wpdb;
$before = $wpdb->num_queries;
$result = graphql( array( 'query' => $query ) );
$total  = $wpdb->num_queries - $before;
gq_batch_assert( empty( $result['errors'] ), wp_json_encode( $result ) );
$nodes = $result['data']['posts']['nodes'];
gq_batch_assert( count( $nodes ) === count( $fixture['posts'] ), 'Missing parent nodes.' );
foreach ( $nodes as $index => $node ) {
	$translations = pll_get_post_translations( $node['databaseId'] );
	$expected     = array();
	foreach ( $translations as $id ) {
		if ( $id !== $node['databaseId'] && ( $admin || 'publish' === get_post_status( $id ) ) ) {
			$expected[] = array( 'databaseId' => $id );
		}
	}
	gq_batch_assert( $node['translations'] === $expected, 'Translation visibility/order mismatch: ' . wp_json_encode( $node ) );
	gq_batch_assert( $node['again'] === $expected, 'Repeated field mismatch.' );
	$fr              = $translations['fr'] ?? null;
	$expected_single = $fr && ( $admin || 'publish' === get_post_status( $fr ) ) ? array( 'databaseId' => $fr ) : null;
	gq_batch_assert( $node['translation'] === $expected_single, 'Single translation mismatch.' );
}
$foreign             = array_merge( array_column( $fixture['posts'], 'fr' ), array_column( $fixture['posts'], 'de' ) );
$translation_batches = array_values(
	array_filter(
		GQ_Batching_Post_Loader::$batches,
		static function ( $keys ) use ( $foreign ) {
			return (bool) array_intersect( $keys, $foreign );
		}
	)
);
WP_CLI::log(
	wp_json_encode(
		array(
			'user'                    => $admin ? 'admin' : 'anonymous',
			'parents'                 => count( $nodes ),
			'loader_batches'          => count( GQ_Batching_Post_Loader::$batches ),
			'translation_batches'     => count( $translation_batches ),
			'translation_batch_sizes' => array_map( 'count', $translation_batches ),
			'loader_sql_queries'      => GQ_Batching_Post_Loader::$queries,
			'total_sql_queries'       => $total,
		)
	)
);
if ( ! $baseline ) {
	gq_batch_assert( count( $translation_batches ) === 1, 'Translations must batch across sibling nodes.' );
	gq_batch_assert( GQ_Batching_Post_Loader::$queries <= 12, 'Post loader query count regressed.' );
	gq_batch_assert( $total <= 20, 'Connection query count regressed.' );
}

// Exercise the shared resolver for terms, including nested translations.
$ids    = implode( ',', array_column( $fixture['terms'], 'en' ) );
$result = graphql( array( 'query' => '{ categories(first: 100, where: {include: [' . $ids . ']}) { nodes { databaseId translations { databaseId translations { databaseId } } } } }' ) );
gq_batch_assert( empty( $result['errors'] ), wp_json_encode( $result ) );
gq_batch_assert( count( $result['data']['categories']['nodes'] ) === 10, 'Missing terms.' );
foreach ( $result['data']['categories']['nodes'] as $node ) {
	$translations = pll_get_term_translations( $node['databaseId'] );
	$expected     = array_values( array_diff( array_values( $translations ), array( $node['databaseId'] ) ) );
	gq_batch_assert( array_column( $node['translations'], 'databaseId' ) === $expected, 'Term order/membership mismatch.' );
	foreach ( $node['translations'] as $translation ) {
		$nested = array_values( array_diff( array_values( $translations ), array( $translation['databaseId'] ) ) );
		gq_batch_assert( array_column( $translation['translations'], 'databaseId' ) === $nested, 'Nested term translations mismatch.' );
	}
}
WP_CLI::success( 'Translation batching correctness checks passed.' );
