<?php
namespace WPLMS_S1I;
class Admin {
		private $page_slug = 'wplms-s1-importer';

		public function hooks() {
			\add_action( 'admin_menu', [ $this, 'menu' ] );
			\add_action( 'admin_post_wplms_s1i_run', [ $this, 'handle_import' ] );
			\add_action( 'admin_post_wplms_s1i_reset', [ $this, 'handle_reset' ] );
		}

		public function menu() {
			\add_submenu_page(
				'tools.php',
				'WPLMS S1 Importer',
				'WPLMS S1 Importer',
				'manage_options',
				$this->page_slug,
				[ $this, 'render' ]
			);
		}

		public function render() {
			if ( ! \current_user_can( 'manage_options' ) ) return;
			$idmap   = new IdMap();
			$stats   = \get_option( \WPLMS_S1I_OPT_RUNSTATS, [] );
			$en_pool = \get_option( \WPLMS_S1I_OPT_ENROLL_POOL, [] );
			?>
			<div class="wrap">
				<h1>WPLMS → LearnDash Importer (PoC)</h1>
				<p>Version <?php echo \esc_html( \WPLMS_S1I_VER ); ?>. Use this to import the JSON created by WPLMS S1 Exporter.</p>

				<h2 class="title">Run Import</h2>
				<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<?php \wp_nonce_field( 'wplms_s1i_run' ); ?>
					<input type="hidden" name="action" value="wplms_s1i_run" />
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="wplms_s1i_file">JSON or ZIP file</label></th>
							<td><input type="file" name="wplms_s1i_file" id="wplms_s1i_file" accept=".json,.zip" required /></td>
						</tr>
						<tr>
							<th scope="row"><label for="wplms_s1i_dry">Dry run</label></th>
							<td><label><input type="checkbox" name="dry" id="wplms_s1i_dry" value="1" /> Analyze only (no content will be created)</label></td>
						</tr>
					</table>
					<?php \submit_button( 'Start Import' ); ?>
				</form>

				<h2 class="title">Utilities</h2>
				<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
					<?php \wp_nonce_field( 'wplms_s1i_reset' ); ?>
					<input type="hidden" name="action" value="wplms_s1i_reset" />
					<?php \submit_button( 'Reset ID Map & Stats', 'delete' ); ?>
				</form>

				<h2 class="title">ID Map (summary)</h2>
				<pre style="max-height:300px;overflow:auto;background:#fff;border:1px solid #ddd;padding:10px"><?php echo \esc_html( print_r( $idmap->get_all(), true ) ); ?></pre>

				<h2 class="title">Last Run Stats</h2>
				<pre style="max-height:300px;overflow:auto;background:#fff;border:1px solid #ddd;padding:10px"><?php echo \esc_html( print_r( $stats, true ) ); ?></pre>

				<h2 class="title">Enrollments Pool (deferred)</h2>
				<p>Stored per original course ID for later user mapping.</p>
				<pre style="max-height:300px;overflow:auto;background:#fff;border:1px solid #ddd;padding:10px"><?php echo \esc_html( print_r( [ 'courses'=> count( $en_pool ) ], true ) ); ?></pre>

			</div>
			<?php
		}

		public function handle_import() {
			if ( ! \current_user_can( 'manage_options' ) ) \wp_die( 'Unauthorized' );
			\check_admin_referer( 'wplms_s1i_run' );

			$dry = isset( $_POST['dry'] ) && $_POST['dry'] == '1';

			// handle upload
			if ( empty( $_FILES['wplms_s1i_file'] ) || empty( $_FILES['wplms_s1i_file']['tmp_name'] ) ) {
				\wp_die( 'No file uploaded' );
			}
			$overrides = [ 'test_form' => false ];
			$uploaded = \wp_handle_upload( $_FILES['wplms_s1i_file'], $overrides );
			if ( isset( $uploaded['error'] ) ) {
				\wp_die( 'Upload error: ' . \esc_html( $uploaded['error'] ) );
			}

			$logger   = new Logger();
			$idmap    = new IdMap();
			$importer = new Importer( $logger, $idmap );
			$importer->set_dry_run( $dry );

			try {
				$stats = $importer->run( $uploaded['file'] );
				$url   = \add_query_arg( [ 'page'=>$this->page_slug, 'done'=>1, 'log'=>rawurlencode( $logger->path() ) ], \admin_url( 'tools.php' ) );
				\wp_safe_redirect( $url );
				exit;
			} catch ( \Throwable $e ) {
				\wp_die( 'Import failed: ' . \esc_html( $e->getMessage() ) );
			}
		}

                public function handle_reset() {
                        if ( ! \current_user_can( 'manage_options' ) ) \wp_die( 'Unauthorized' );
                        \check_admin_referer( 'wplms_s1i_reset' );
                        $idmap = new IdMap();
                        $idmap->reset();
                        \delete_option( \WPLMS_S1I_OPT_RUNSTATS );
                        \delete_option( \WPLMS_S1I_OPT_ENROLL_POOL );
                        \wp_safe_redirect( \add_query_arg( [ 'page'=>$this->page_slug, 'reset'=>1 ], \admin_url( 'tools.php' ) ) );
                        exit;
                }
        }
