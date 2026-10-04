<?php
/**
 * Schema regression assertions on an isolated site, in a fresh WP load for
 * each phase (before languages exist, configured, and after deleting the last).
 * Run through run-empty-languages.sh, not against an existing site's database.
 *
 * @package GQ\PolylangGraphQL
 */

use GQ\PolylangGraphQL\Content;
use GQ\PolylangGraphQL\Languages;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Introspection;

/**
 * Fails the test even when PHP assertions are disabled.
 *
 * @param bool   $condition Whether the assertion passed.
 * @param string $message   Failure description.
 */
function gq_empty_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		WP_CLI::error( $message );
	}
}

/**
 * Executes a query and rejects GraphQL errors.
 *
 * @param string $query The GraphQL query.
 * @return array<string,mixed>
 */
function gq_empty_query( string $query ): array {
	$result = graphql( array( 'query' => $query ) );
	gq_empty_assert( empty( $result['errors'] ), wp_json_encode( $result ) );
	return $result['data'];
}

$gq_configured = 'configured' === ( $args[0] ?? '' );
gq_empty_assert( ! empty( Languages::all() ) === $gq_configured, 'Unexpected language fixture.' );

// Ordinary queries must work without any plugin-specific fields or arguments.
$gq_data = gq_empty_query(
	'{ posts { nodes { databaseId } } pages { nodes { databaseId } }
	categories { nodes { databaseId } } tags { nodes { databaseId } }
	contentNodes { nodes { databaseId } } menuItems { nodes { databaseId } } }'
);
gq_empty_assert( isset( $gq_data['posts']['nodes'], $gq_data['categories']['nodes'] ), 'Missing ordinary connection data.' );

// WPGraphQL restricts public introspection by default; use the test admin.
// Full introspection and validation force every input and output type to load.
wp_set_current_user( 1 );
gq_empty_query( Introspection::getIntrospectionQuery() );
wp_set_current_user( 0 );
$gq_schema = WPGraphQL::get_schema();
$gq_schema->assertValid();
foreach ( array( 'LanguageCodeEnum', 'LanguageCodeFilterEnum', 'Language' ) as $gq_type ) {
	gq_empty_assert( ( null !== $gq_schema->getType( $gq_type ) ) === $gq_configured, 'Unexpected type: ' . $gq_type );
}

foreach ( array( 'Post', 'Page', 'Category', 'Tag', 'ContentNode' ) as $gq_type ) {
	$gq_input  = 'RootQueryTo' . $gq_type . 'ConnectionWhereArgs';
	$gq_fields = $gq_schema->getType( $gq_input )->getFields();
	foreach ( array(
		'language'  => 'LanguageCodeFilterEnum',
		'languages' => 'LanguageCodeEnum',
	) as $gq_field => $gq_enum ) {
		gq_empty_assert( isset( $gq_fields[ $gq_field ] ) === $gq_configured, 'Unexpected field: ' . $gq_input . '.' . $gq_field );
		if ( $gq_configured ) {
			gq_empty_assert( Type::getNamedType( $gq_fields[ $gq_field ]->getType() )->name === $gq_enum, 'Wrong language argument type.' );
		}
	}
	if ( ! $gq_configured ) {
		$gq_original = array( 'existing' => array( 'type' => 'String' ) );
		gq_empty_assert( Content::where_fields( $gq_original, $gq_input ) === $gq_original, 'Existing input fields changed.' );
	}
}

$gq_menu_fields = $gq_schema->getType( 'RootQueryToMenuItemConnectionWhereArgs' )->getFields();
gq_empty_assert( isset( $gq_menu_fields['language'] ) === $gq_configured, 'Unexpected menu language argument.' );
if ( $gq_configured ) {
	$gq_data = gq_empty_query(
		'{ languages { code } defaultLanguage { code }
		posts(where: { language: DEFAULT }) { nodes { language { code } translation(language: EN) { databaseId } } }
		pages(where: { languages: [EN] }) { nodes { databaseId } }
		categories(where: { language: ALL }) { nodes { databaseId } } }'
	);
	gq_empty_assert( array( array( 'code' => 'EN' ) ) === $gq_data['languages'], 'Configured language enum changed.' );
	gq_empty_assert( array( 'code' => 'EN' ) === $gq_data['defaultLanguage'], 'Default language changed.' );
}

WP_CLI::success( 'Schema queries and introspection passed: ' . ( $args[0] ?? 'empty' ) );
