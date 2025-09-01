<?php
namespace WPLMS_S1I;

class IdMap {
    private $map;

    public function __construct() {
        $this->map = \get_option( \WPLMS_S1I_OPT_IDMAP, [
            'courses'     => [],
            'units'       => [], // mapped to Lessons in LearnDash
            'quizzes'     => [],
            'assignments' => [],
            'certificates'=> [],
        ] );
    }

    public function get_all() { return $this->map; }

    public function get( $type, $old_id ) { return isset( $this->map[ $type ][ $old_id ] ) ? (int) $this->map[ $type ][ $old_id ] : 0; }

    public function set( $type, $old_id, $new_id ) {
        $this->map[ $type ][ (string) $old_id ] = (int) $new_id;
        \update_option( \WPLMS_S1I_OPT_IDMAP, $this->map, false );
    }

    public function reset() {
        $this->map = [ 'courses'=>[], 'units'=>[], 'quizzes'=>[], 'assignments'=>[], 'certificates'=>[] ];
        \update_option( \WPLMS_S1I_OPT_IDMAP, $this->map, false );
    }
}

