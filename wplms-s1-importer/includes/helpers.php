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

    // 2) Core хелпери
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    // 3) Завантаження з м’яким обробленням помилок
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

    $path     = parse_url( $url, PHP_URL_PATH );
    $filename = $path ? \wp_basename( $path ) : 'remote-file';

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
