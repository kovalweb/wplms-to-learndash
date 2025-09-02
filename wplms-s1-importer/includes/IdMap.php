<?php
namespace WPLMS_S1I;

class IdMap {
    private $map;

    public function __construct() {
        $this->map = \get_option( \WPLMS_S1I_OPT_IDMAP, [
            'courses'      => [],
            'units'        => [], // mapped to Lessons in LearnDash
            'quizzes'      => [],
            'assignments'  => [],
            'certificates' => [],
        ] );
    }

    public function get_all() { return $this->map; }

    public function get_entry( $type, $old_id ) {
        return isset( $this->map[ $type ][ $old_id ] ) ? $this->map[ $type ][ $old_id ] : null;
    }

    public function get( $type, $old_id ) {
        $entry = $this->get_entry( $type, $old_id );
        return $entry ? (int) array_get( $entry, 'id', 0 ) : 0;
    }

    public function set( $type, $old_id, $new_id, $slug = '' ) {
        $this->map[ $type ][ (string) $old_id ] = [
            'id'          => (int) $new_id,
            'slug'        => (string) $slug,
            'imported_at' => time(),
        ];
        \update_option( \WPLMS_S1I_OPT_IDMAP, $this->map, false );
    }

    public function reset() {
        $this->map = [ 'courses'=>[], 'units'=>[], 'quizzes'=>[], 'assignments'=>[], 'certificates'=>[] ];
        \update_option( \WPLMS_S1I_OPT_IDMAP, $this->map, false );
    }
}

