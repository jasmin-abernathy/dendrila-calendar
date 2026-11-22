<?php
/** Bundled locale integrity check. */
define( 'ABSPATH', __DIR__ );
$data = include dirname( __DIR__ ) . '/languages/dendrila-calendar-fr_FR.l10n.php';
$messages = $data['messages'] ?? array();
if ( count( $messages ) !== 32 || ! isset( $messages['Date and time'], $messages['Sources'] ) ) {
    throw new RuntimeException( 'French translation file is missing messages' );
}
foreach ( $messages as $en => $fr ) {
    if ( ! is_string( $fr ) || '' === trim( $fr ) ) {
        throw new RuntimeException( 'Empty translation for: ' . $en );
    }
}
echo "French translation coverage passed\n";
