<?php
namespace WPLMS_S1I;

/**
 * Admin UI for reset / cleanup.
 */
class ResetAdmin {
    private $page_slug = 'wplms-s1-importer-reset';

    public function hooks() {
        \add_action( 'admin_menu', [ $this, 'menu' ] );
        \add_action( 'admin_post_wplms_s1i_run_reset', [ $this, 'handle' ] );
    }

    public function menu() {
        \add_submenu_page(
            'tools.php',
            'WPLMS → LearnDash Reset',
            'WPLMS → LearnDash Reset',
            'manage_options',
            $this->page_slug,
            [ $this, 'render' ]
        );
    }

    public function render() {
        if ( ! \current_user_can( 'manage_options' ) ) return;
        $report = null;
        if ( isset( $_GET['report'] ) ) {
            $report = \get_transient( 'wplms_s1i_last_reset_report' );
        }
        ?>
        <div class="wrap">
            <h1>WPLMS → LearnDash Reset / Cleanup</h1>
            <?php if ( $report ) : ?>
                <h2 class="title">Last run</h2>
                <pre style="max-height:300px;overflow:auto;background:#fff;border:1px solid #ddd;padding:10px">
<?php echo esc_html( print_r( $report, true ) ); ?>
</pre>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url( \admin_url( 'admin-post.php' ) ); ?>">
                <?php \wp_nonce_field( 'wplms_s1i_run_reset' ); ?>
                <input type="hidden" name="action" value="wplms_s1i_run_reset" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Content types</th>
                        <td>
                            <label><input type="checkbox" name="types[]" value="sfwd-courses" /> Courses</label><br />
                            <label><input type="checkbox" name="types[]" value="sfwd-lessons" /> Lessons</label><br />
                            <label><input type="checkbox" name="types[]" value="sfwd-topic" /> Topics</label><br />
                            <label><input type="checkbox" name="types[]" value="sfwd-quiz" /> Quizzes</label><br />
                            <label><input type="checkbox" name="types[]" value="sfwd-question" /> Questions</label><br />
                            <label><input type="checkbox" name="types[]" value="sfwd-assignment" /> Assignments</label><br />
                            <label><input type="checkbox" name="types[]" value="sfwd-certificates" /> Certificates</label><br />
                            <label><input type="checkbox" name="types[]" value="groups" /> Groups</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Scope</th>
                        <td>
                            <label><input type="radio" name="scope" value="imported" checked /> Imported only</label><br />
                            <label><input type="radio" name="scope" value="all" /> All items</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Options</th>
                        <td>
                            <label><input type="checkbox" name="dry" value="1" checked /> Dry run</label><br />
                            <label><input type="checkbox" name="force" value="1" /> Empty trash (force delete)</label><br />
                            <label><input type="checkbox" name="delete_attachments" value="1" /> Also delete attachments</label>
                        </td>
                    </tr>
                </table>
                <?php \submit_button( 'Run Cleanup' ); ?>
            </form>
        </div>
        <?php
    }

    public function handle() {
        if ( ! \current_user_can( 'manage_options' ) ) \wp_die( 'Unauthorized' );
        \check_admin_referer( 'wplms_s1i_run_reset' );
        $types = isset( $_POST['types'] ) ? array_map( 'sanitize_text_field', (array) $_POST['types'] ) : [];
        if ( empty( $types ) ) {
            \wp_redirect( \admin_url( 'tools.php?page=' . $this->page_slug ) );
            exit;
        }
        $scope = isset( $_POST['scope'] ) && $_POST['scope'] === 'all' ? 'all' : 'imported';
        $force = isset( $_POST['force'] );
        $del_att = isset( $_POST['delete_attachments'] );
        $dry = isset( $_POST['dry'] );
        $reset = new Reset();
        $report = $reset->run( $types, $scope, $force, $del_att, $dry );
        \set_transient( 'wplms_s1i_last_reset_report', $report, 300 );
        \wp_redirect( \admin_url( 'tools.php?page=' . $this->page_slug . '&report=1' ) );
        exit;
    }
}
