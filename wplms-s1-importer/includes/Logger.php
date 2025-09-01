<?php
namespace WPLMS_S1I;

class Logger {
    private $dir;
    private $file;

    public function __construct() {
        $uploads   = \wp_upload_dir();
        $this->dir = \trailingslashit( $uploads['basedir'] ) . 'wplms-s1-importer/logs/';
        \wp_mkdir_p( $this->dir );
        $stamp     = gmdate( 'Ymd-His' );
        $this->file = $this->dir . 'import-' . $stamp . '.log';
    }

    public function path() { return $this->file; }

    public function write( $msg, $context = [] ) {
        if ( ! is_string( $msg ) ) {
            $msg = print_r( $msg, true );
        }
        $line = '[' . gmdate( 'c' ) . '] ' . $msg;
        if ( $context ) {
            $line .= ' ' . \wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        }
        $line .= "\n";
        file_put_contents( $this->file, $line, FILE_APPEND );
    }
}

