<?php
spl_autoload_register( function ( $class ) {
    $prefix = 'WPLMS_S1I\\';
    if ( 0 !== strpos( $class, $prefix ) ) {
        return;
    }
    $relative = substr( $class, strlen( $prefix ) );
    $relative_path = str_replace( '\\', '/', $relative );
    $file = WPLMS_S1I_DIR . 'includes/' . $relative_path . '.php';
    if ( file_exists( $file ) ) {
        require $file;
    }
} );
