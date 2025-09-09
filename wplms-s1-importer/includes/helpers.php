<?php
namespace WPLMS_S1I;

function array_get( $arr, $key, $default = '' ) {
    if ( ! is_array( $arr ) ) return $default;
    if ( false === strpos( $key, '.' ) ) {
        return isset( $arr[ $key ] ) ? $arr[ $key ] : $default;
    }
    $segments = explode( '.', $key );
    foreach ( $segments as $segment ) {
        if ( ! is_array( $arr ) || ! array_key_exists( $segment, $arr ) ) {
            return $default;
        }
        $arr = $arr[ $segment ];
    }
    return $arr;
}

function normalize_slug( $slug ) {
    $slug = \sanitize_title( $slug );
    return $slug ?: null;
}

function ensure_oembed( $content, $embeds ) {
    if ( empty( $embeds ) || ! is_array( $embeds ) ) return $content;
    $lines = [];
    foreach ( $embeds as $item ) {
        if ( is_array( $item ) ) {
            $url = trim( (string) array_get( $item, 'src', '' ) );
        } else {
            $url = trim( (string) $item );
        }
        if ( $url ) $lines[] = $url; // Let WP auto-oEmbed handle it
    }
    if ( $lines ) {
        $content .= "\n\n<!-- WPLMS S1 embeds -->\n" . implode( "\n", $lines ) . "\n";
    }
    return $content;
}

/**
 * Повертає URL-рядок з довільного значення (рядок або масив зі стандартними ключами).
 */
function extract_url( $value ) {
    if ( is_array( $value ) ) {
        $u = array_get( $value, 'url', '' );
        if ( ! $u ) $u = array_get( $value, 'source_url', '' );
        if ( ! $u ) $u = array_get( $value, 'guid', '' );
        if ( ! $u ) $u = array_get( $value, 'src', '' );
        return is_string( $u ) ? trim( $u ) : '';
    }
    return is_string( $value ) ? trim( $value ) : '';
}

/**
 * Resolve LearnDash course category base (ld_course_category) robustly.
 * Tries option, settings sections, then derives from a sample term URL.
 *
 * @return array{base:string, source:string, sample_url:string}
 */
function hv_ld_get_course_category_base(): array {
    $base       = '';
    $source     = 'unknown';
    $sample_url = '';

    // 1) Try common option
    $opt = \get_option( 'learndash_settings_permalinks' );
    if ( is_array( $opt ) ) {
        if ( ! empty( $opt['course_category_base'] ) ) {
            $base   = (string) $opt['course_category_base'];
            $source = 'option:learndash_settings_permalinks.course_category_base';
        } elseif ( ! empty( $opt['course_category'] ) ) {
            $base   = (string) $opt['course_category'];
            $source = 'option:learndash_settings_permalinks.course_category';
        }
    }

    // 2) Try settings sections (varies by LD versions)
    if ( $base === '' && class_exists( 'LearnDash_Settings_Section' ) ) {
        try {
            if ( method_exists( 'LearnDash_Settings_Section', 'get_section_settings_all' ) ) {
                $sections = \LearnDash_Settings_Section::get_section_settings_all();
                foreach ( (array) $sections as $section_settings ) {
                    if ( ! is_array( $section_settings ) ) continue;
                    if ( ! empty( $section_settings['course_category_base'] ) ) {
                        $base   = (string) $section_settings['course_category_base'];
                        $source = 'settings_section:course_category_base';
                        break;
                    }
                    if ( ! empty( $section_settings['course_category'] ) ) {
                        $base   = (string) $section_settings['course_category'];
                        $source = 'settings_section:course_category';
                        break;
                    }
                }
            }
        } catch ( \Throwable $e ) {
            // ignore
        }
    }

    // 3) Fallback: derive from a sample term URL
    $terms = \get_terms( [
        'taxonomy'   => 'ld_course_category',
        'number'     => 1,
        'hide_empty' => false,
        'fields'     => 'all',
    ] );

    if ( ! \is_wp_error( $terms ) && ! empty( $terms ) ) {
        $sample_url = \get_term_link( $terms[0], 'ld_course_category' );
        if ( is_string( $sample_url ) && $sample_url !== '' ) {
            $path  = \wp_parse_url( $sample_url, PHP_URL_PATH );
            $path  = trim( (string) $path, '/' );
            $parts = array_values( array_filter( explode( '/', $path ) ) );
            // Expect .../<base>/<term-slug>
            if ( count( $parts ) >= 2 ) {
                $derived_base = $parts[ count( $parts ) - 2 ];
                if ( $base === '' ) {
                    $base   = $derived_base;
                    $source = 'derived_from_url';
                } elseif ( $base !== $derived_base ) {
                    // keep mismatch info for debugging
                    $source .= '|mismatch:derived=' . $derived_base;
                }
            }
        }
    }

    return [
        'base'       => (string) $base,
        'source'     => (string) $source,
        'sample_url' => (string) $sample_url,
    ];
}

/**
 * Link a LearnDash course to a WooCommerce product (soft).
 *
 * Updates simple meta references on both objects. No validation is performed
 * beyond ensuring the IDs are positive integers.
 */
/**
 * Sideload featured image.
 * $image може бути рядком URL або масивом (url/source_url/guid/src).
 * На помилках не кидаємо фатал — пишемо в лог і повертаємо 0.
 */
function sideload_featured( $image, $attach_to_post_id, Logger $logger, ?array &$stats = null ) {
    // 1) Розв’язуємо URL із довільної структури
    $url = '';
    if ( is_array( $image ) ) {
        $url = array_get( $image, 'url', '' );
        if ( ! $url ) $url = array_get( $image, 'source_url', '' );
        if ( ! $url ) $url = array_get( $image, 'guid', '' );
        if ( ! $url ) $url = array_get( $image, 'src', '' ); // інколи так
    } else {
        $url = (string) $image;
    }
    $url = trim( $url );

    if ( $url === '' ) {
        if ( is_array( $stats ) ) {
            $stats['images_skipped_empty'] = array_get( $stats, 'images_skipped_empty', 0 ) + 1;
        }
        return 0;
    }

    $path     = parse_url( $url, PHP_URL_PATH );
    $filename = $path ? \wp_basename( $path ) : '';

    // 2) Try reuse existing attachment by source URL or filename
    $attach_id = 0;
    $existing = get_posts( [
        'post_type'      => 'attachment',
        'posts_per_page' => 1,
        'post_status'    => 'any',
        'meta_key'       => '_wplms_source_url',
        'meta_value'     => $url,
        'fields'         => 'ids',
    ] );
    if ( $existing ) {
        $attach_id = (int) $existing[0];
    } elseif ( $filename ) {
        $existing = get_posts( [
            'post_type'      => 'attachment',
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'fields'         => 'ids',
            'meta_query'     => [ [ 'key' => '_wp_attached_file', 'value' => $filename, 'compare' => 'LIKE' ] ],
        ] );
        if ( $existing ) {
            $attach_id = (int) $existing[0];
        }
    }
    if ( $attach_id ) {
        \set_post_thumbnail( $attach_to_post_id, $attach_id );
        return $attach_id;
    }

    // 3) Core helpers for download
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $timeout = 60;
    try {
        $tmp = \download_url( $url, $timeout );
    } catch ( \Throwable $t ) {
        $logger->write( 'media download error', [ 'url' => $url, 'error' => $t->getMessage() ] );
        if ( is_array( $stats ) ) {
            $stats['images_errors'] = array_get( $stats, 'images_errors', 0 ) + 1;
        }
        return 0;
    }

    if ( \is_wp_error( $tmp ) ) {
        $logger->write( 'media download failed', [ 'url' => $url, 'error' => $tmp->get_error_message() ] );
        if ( is_array( $stats ) ) {
            $stats['images_errors'] = array_get( $stats, 'images_errors', 0 ) + 1;
        }
        return 0;
    }

    $filename = $filename ?: ( $path ? \wp_basename( $path ) : 'remote-file' );

    $file     = [
        'name'     => $filename ?: 'remote-file',
        'tmp_name' => $tmp,
    ];
    $overrides = [ 'test_form' => false ];
    $results   = \wp_handle_sideload( $file, $overrides );

    if ( isset( $results['error'] ) ) {
        @unlink( $tmp );
        $logger->write( 'media sideload failed', [ 'url' => $url, 'error' => $results['error'] ] );
        if ( is_array( $stats ) ) {
            $stats['images_errors'] = array_get( $stats, 'images_errors', 0 ) + 1;
        }
        return 0;
    }

    $attachment = [
        'post_mime_type' => $results['type'] ?? '',
        'post_title'     => \sanitize_file_name( $filename ),
        'post_content'   => '',
        'post_status'    => 'inherit',
    ];
    $attach_id = \wp_insert_attachment( $attachment, $results['file'], $attach_to_post_id );

    if ( ! \is_wp_error( $attach_id ) ) {
        \wp_update_attachment_metadata( $attach_id, \wp_generate_attachment_metadata( $attach_id, $results['file'] ) );
        \set_post_thumbnail( $attach_to_post_id, $attach_id );
        \update_post_meta( $attach_id, '_wplms_source_url', $url );
        if ( is_array( $stats ) ) {
            $stats['images_downloaded'] = array_get( $stats, 'images_downloaded', 0 ) + 1;
        }
        return (int) $attach_id;
    }

    $logger->write( 'attachment insert failed', [ 'url' => $url, 'error' => $attach_id->get_error_message() ] );
    if ( is_array( $stats ) ) {
        $stats['images_errors'] = array_get( $stats, 'images_errors', 0 ) + 1;
    }
    return 0;
}

/**
 * Create minimal ProQuiz master record and return its ID.
 *
 * @param int   $quiz_post_id Related sfwd-quiz post ID.
 * @param array $data         Optional data: name, title, show_points...
 *
 * @return int  Master quiz ID or 0 on failure.
 */
function create_proquiz_master( $quiz_post_id, $data = [] ) {
    global $wpdb;

    $table = $wpdb->prefix . 'wp_pro_quiz_master';

    // If already exists for this post, return existing ID.
    $existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE quiz_post_id = %d", $quiz_post_id ) );
    if ( $existing > 0 ) {
        return $existing;
    }

    $defaults = [
        'name'                  => '',
        'title'                 => '',
        'text'                  => '',
        'result_text'           => '',
        'toplist_activated'     => 0,
        'show_max_question'     => 0,
        'show_max_question_value' => 0,
        'show_points'           => 1,
        'statistics_on'        => 0,
        'statistics_ip_lock'   => 0,
        'quiz_post_id'         => (int) $quiz_post_id,
        'author_id'            => \get_current_user_id() ?: 0,
        'created'              => \current_time( 'mysql', true ),
    ];

    $row = \wp_parse_args( $data, $defaults );

    $inserted = $wpdb->insert( $table, $row );
    if ( $inserted ) {
        return (int) $wpdb->insert_id;
    }

    return 0;
}

function cleanup_duplicate_certificates( IdMap $idmap ) {
    $posts = \get_posts( [
        'post_type'      => 'sfwd-certificates',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_key'       => '_wplms_old_id',
    ] );
    $groups = [];
    foreach ( $posts as $pid ) {
        $old = (int) \get_post_meta( $pid, '_wplms_old_id', true );
        if ( $old > 0 ) {
            $groups[ $old ][] = $pid;
        }
    }
    $group_count = count( $groups );
    $deleted = 0;
    foreach ( $groups as $old_id => $ids ) {
        sort( $ids, SORT_NUMERIC );
        $keep = array_shift( $ids );
        $slug = \get_post_field( 'post_name', $keep );
        $idmap->set( 'certificate', $old_id, $keep, $slug );
        foreach ( $ids as $dup ) {
            \wp_delete_post( $dup, true );
            $deleted++;
        }
    }
    return [ 'groups' => $group_count, 'deleted' => $deleted ];
}
