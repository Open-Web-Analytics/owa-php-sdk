<?php

/**
 * Autoloader for installs that do not use Composer's own autoloader.
 *
 * Projects that installed the SDK with Composer should include
 * vendor/autoload.php instead. The SDK has no runtime dependencies, so this
 * file is all a manual install needs.
 */

spl_autoload_register( function ( $class ) {

    $prefix = 'OwaSdk\\';

    if ( ! str_starts_with( $class, $prefix ) ) {

        return;
    }

    $file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';

    if ( is_file( $file ) ) {

        require $file;
    }

}, true );
