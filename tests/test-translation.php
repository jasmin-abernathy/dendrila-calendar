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
        "/(?:__|esc_html__|esc_attr__)\s*\(\s*'((?:\\\\'|[^'])*)'\s*,\s*'dendrila-calendar'/",
        file_get_contents( $path ),
        $matches
    );
    foreach ( $matches[1] as $source ) {
        $expected[ str_replace( "\\'", "'", $source ) ] = true;
    }
}
foreach ( array_keys( $expected ) as $english ) {
    if ( ! array_key_exists( $english, $messages ) || ! is_string( $messages[ $english ] ) || '' === trim( $messages[ $english ] ) ) {
        throw new RuntimeException( 'Missing French translation: ' . $english );
    }
}
if ( count( $messages ) !== count( $expected ) ) {
    throw new RuntimeException( 'Translation catalog does not match source strings' );
}
echo count( $messages ) . " French translations verified against PHP source\n";
