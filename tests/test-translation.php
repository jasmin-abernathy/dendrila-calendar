<?php
/** Verify bundled French translations against every PHP gettext call. */
define( 'ABSPATH', __DIR__ );
$data = include dirname( __DIR__ ) . '/languages/dendrila-calendar-fr_FR.l10n.php';
$messages = $data['messages'] ?? array();
$paths = array_merge(
    glob( dirname( __DIR__ ) . '/*.php' ),
    glob( dirname( __DIR__ ) . '/includes/*.php' )
);
$expected = array();
foreach ( $paths as $path ) {
    preg_match_all(
        "/(?<![A-Za-z0-9_])(?:__|esc_html__|esc_attr__|_e|esc_html_e|esc_attr_e)\s*\(\s*'((?:\\\\'|[^'])*)'\s*,\s*'dendrila-calendar'/",
        file_get_contents( $path ),
        $matches
    );
    foreach ( $matches[1] as $source ) {
        $expected[ str_replace( "\\'", "'", $source ) ] = true;
    }
}
// Regression: names from the detector are data, not translatable string literals.
foreach ( $paths as $path ) {
    $php = file_get_contents( $path );
    if ( preg_match( '/(?<![A-Za-z0-9_])(?:__|esc_html__|esc_attr__|_e|esc_html_e|esc_attr_e)\\s*\\(\\s*\\$/', $php ) ) {
        throw new RuntimeException( 'Dynamic translation key in ' . basename( $path ) );
    }
}
foreach ( array_keys( $expected ) as $english ) {
    if ( ! array_key_exists( $english, $messages ) || ! is_string( $messages[ $english ] ) || '' === trim( $messages[ $english ] ) ) {
        throw new RuntimeException( 'Missing French translation: ' . $english );
    }
    preg_match_all( '/%(?:[0-9]+\\$)?[sd]/', $english, $english_placeholders );
    preg_match_all( '/%(?:[0-9]+\\$)?[sd]/', $messages[ $english ], $french_placeholders );
    sort( $english_placeholders[0] );
    sort( $french_placeholders[0] );
    if ( $english_placeholders[0] !== $french_placeholders[0] ) {
        throw new RuntimeException( 'Translation changed format placeholders: ' . $english );
    }
}
if ( count( $messages ) !== count( $expected ) ) {
    throw new RuntimeException( 'Translation catalog does not match source strings' );
}
if ( ( $messages['Group'] ?? '' ) !== 'Regrouper' ||
     isset( $messages['Consolidate duplicate connection'] ) ||
     ( $messages['Connections grouped. No calendar events were deleted.'] ?? '' ) !==
         'Connexions regroupées. Aucun événement de l’agenda n’a été supprimé.' ) {
    throw new RuntimeException( 'Duplicate-connection action must use simple French wording.' );
}
// Public form labels rendered through echo-style gettext calls must be covered too.
foreach ( array(
    'Choose a time that works for you' => 'Choisissez l’horaire qui vous convient',
    'Available times' => 'Horaires disponibles',
    'I agree that my name and email address may be used to manage this booking.' => 'J’accepte que mon nom et mon adresse e-mail soient utilisés pour gérer cette réservation.',
    'Weekly hours' => 'Horaires hebdomadaires',
    'Booking rules' => 'Règles de réservation',
) as $english => $french ) {
    if ( ! isset( $expected[ $english ] ) || ( $messages[ $english ] ?? '' ) !== $french ) {
        throw new RuntimeException( 'Booking UI translation was not extracted or translated: ' . $english );
    }
}
echo count( $messages ) . " French translations verified against PHP source\n";
