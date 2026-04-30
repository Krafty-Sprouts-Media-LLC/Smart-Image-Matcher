<?php
/**
 * Featured image auto assigner for slug-based matching.
 *
 * @package SmartImageMatcher
 * @since 2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds tools for assigning featured images from filename/slug matches.
 *
 * @since 2.6.0
 */
class SIM_Featured_Image_Auto_Assigner {

	/**
	 * Cache key for attachment slug map.
	 *
	 * @since 2.6.0
	 * @var string
	 */
	const ATTACHMENT_MAP_CACHE_KEY = 'sim_fiaa_attachment_slug_map';

	/**
	 * Attachment slug map cache TTL in seconds.
	 *
	 * @since 2.6.0
	 * @var int
	 */
	const ATTACHMENT_MAP_CACHE_TTL = 1800;

	/**
	 * Default post batch size for manual runs.
	 *
	 * @since 2.6.0
	 * @var int
	 */
	const DEFAULT_BATCH_SIZE = 200;

	/**
	 * Singleton instance.
	 *
	 * @since 2.6.0
	 * @var SIM_Featured_Image_Auto_Assigner|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @since 2.6.0
	 * @return SIM_Featured_Image_Auto_Assigner
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks.
	 *
	 * @since 2.6.0
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'add_attachment', array( $this, 'on_image_upload' ) );
		add_action( 'delete_attachment', array( $this, 'clear_attachment_slug_cache' ) );
		add_action( 'edit_attachment', array( $this, 'clear_attachment_slug_cache' ) );
		add_action( 'sim_fiaa_cron_run', array( $this, 'run_scheduled_assignment' ) );
	}

	/**
	 * Register Media submenu.
	 *
	 * @since 2.6.0
	 * @return void
	 */
	public function register_menu() {
		add_media_page(
			__( 'Featured Image Auto-Assigner', 'smart-image-matcher' ),
			__( 'Image Auto-Assigner', 'smart-image-matcher' ),
			'manage_options',
			'sim-featured-image-auto-assigner',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Render admin page.
	 *
	 * @since 2.6.0
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$post_types    = $this->get_supported_post_types();
		$selected_type = 'post';
		$overwrite     = false;
		$results       = null;

		if ( isset( $_POST['sim_fiaa_run'] ) ) {
			check_admin_referer( 'sim_fiaa_run_action', 'sim_fiaa_nonce' );

			$selected_type = isset( $_POST['sim_fiaa_post_type'] ) ? sanitize_key( wp_unslash( $_POST['sim_fiaa_post_type'] ) ) : 'post';
			$overwrite     = isset( $_POST['sim_fiaa_overwrite'] ) && '1' === wp_unslash( $_POST['sim_fiaa_overwrite'] );
			$results       = $this->run_matcher( $selected_type, $overwrite );
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Featured Image Auto-Assigner', 'smart-image-matcher' ); ?></h1>
			<p><?php esc_html_e( 'Match each post slug with an uploaded image filename and assign it as the featured image.', 'smart-image-matcher' ); ?></p>

			<form method="post">
				<?php wp_nonce_field( 'sim_fiaa_run_action', 'sim_fiaa_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sim_fiaa_post_type"><?php esc_html_e( 'Post Type', 'smart-image-matcher' ); ?></label></th>
						<td>
							<select name="sim_fiaa_post_type" id="sim_fiaa_post_type">
								<?php foreach ( $post_types as $post_type ) : ?>
									<option value="<?php echo esc_attr( $post_type ); ?>" <?php selected( $selected_type, $post_type ); ?>>
										<?php echo esc_html( $post_type ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Overwrite Existing', 'smart-image-matcher' ); ?></th>
						<td>
							<label for="sim_fiaa_overwrite">
								<input type="checkbox" id="sim_fiaa_overwrite" name="sim_fiaa_overwrite" value="1" <?php checked( $overwrite ); ?> />
								<?php esc_html_e( 'Replace already assigned featured images.', 'smart-image-matcher' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Run Matcher', 'smart-image-matcher' ), 'primary', 'sim_fiaa_run' ); ?>
			</form>

			<?php if ( is_array( $results ) ) : ?>
				<hr />
				<h2><?php esc_html_e( 'Results', 'smart-image-matcher' ); ?></h2>
				<?php $this->render_results( $results ); ?>
			<?php endif; ?>

			<hr />
			<?php $this->render_stats(); ?>
		</div>
		<?php
	}

	/**
	 * Main matching engine.
	 *
	 * @since 2.6.0
	 * @param string $post_type Post type to process.
	 * @param bool   $overwrite Whether to overwrite current thumbnails.
	 * @return array<string,mixed>
	 */
	private function run_matcher( $post_type, $overwrite ) {
		$results = array(
			'matched'   => array(),
			'skipped'   => array(),
			'unmatched' => array(),
			'total'     => 0,
		);

		$attachment_slug_map = $this->get_attachment_slug_map();
		$batch_size          = $this->get_batch_size();
		$page                = 1;

		do {
			$args = array(
				'post_type'              => sanitize_key( $post_type ),
				'post_status'            => array( 'publish', 'draft', 'pending', 'future' ),
				'posts_per_page'         => $batch_size,
				'paged'                  => $page,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			);

			if ( ! $overwrite ) {
				$args['meta_query'] = array(
					array(
						'key'     => '_thumbnail_id',
						'compare' => 'NOT EXISTS',
					),
				);
			}

			$post_ids = get_posts( $args );

			foreach ( $post_ids as $post_id ) {
				$post = get_post( $post_id );
				if ( ! $post instanceof WP_Post ) {
					continue;
				}

				++$results['total'];

				$slug = $post->post_name;
				if ( empty( $slug ) ) {
					$results['unmatched'][] = array(
						'id'     => $post_id,
						'title'  => get_the_title( $post_id ),
						'slug'   => '(empty)',
						'reason' => __( 'Post has no slug.', 'smart-image-matcher' ),
					);
					continue;
				}

				if ( ! $overwrite && has_post_thumbnail( $post_id ) ) {
					$results['skipped'][] = array(
						'id'    => $post_id,
						'title' => get_the_title( $post_id ),
						'slug'  => $slug,
					);
					continue;
				}

				$attachment_id = $this->find_attachment_by_slug( $slug, $attachment_slug_map );
				if ( null !== $attachment_id ) {
					set_post_thumbnail( $post_id, $attachment_id );
					$results['matched'][] = array(
						'id'            => $post_id,
						'title'         => get_the_title( $post_id ),
						'slug'          => $slug,
						'attachment_id' => $attachment_id,
					);
				} else {
					$results['unmatched'][] = array(
						'id'     => $post_id,
						'title'  => get_the_title( $post_id ),
						'slug'   => $slug,
						'reason' => __( 'No matching image filename found.', 'smart-image-matcher' ),
					);
				}
			}

			++$page;
		} while ( ! empty( $post_ids ) );

		return $results;
	}

	/**
	 * Build and cache attachment slug map.
	 *
	 * @since 2.6.0
	 * @return array<string,int>
	 */
	private function get_attachment_slug_map() {
		$cached_map = get_transient( self::ATTACHMENT_MAP_CACHE_KEY );
		if ( is_array( $cached_map ) ) {
			return $cached_map;
		}

		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT ID, post_name
			FROM {$wpdb->posts}
			WHERE post_type = 'attachment'
			AND post_status = 'inherit'
			AND post_name <> ''",
			ARRAY_A
		);

		$slug_map = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( empty( $row['post_name'] ) || isset( $slug_map[ $row['post_name'] ] ) ) {
					continue;
				}

				$slug_map[ $row['post_name'] ] = (int) $row['ID'];
			}
		}

		set_transient( self::ATTACHMENT_MAP_CACHE_KEY, $slug_map, self::ATTACHMENT_MAP_CACHE_TTL );

		return $slug_map;
	}

	/**
	 * Find attachment ID by slug/filename.
	 *
	 * @since 2.6.0
	 * @param string         $slug      Post slug.
	 * @param array<string,int> $slug_map Attachment map keyed by slug.
	 * @return int|null
	 */
	private function find_attachment_by_slug( $slug, $slug_map ) {
		if ( isset( $slug_map[ $slug ] ) ) {
			return (int) $slug_map[ $slug ];
		}

		global $wpdb;

		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type = 'attachment'
				AND post_status = 'inherit'
				AND post_name = %s
				LIMIT 1",
				$slug
			)
		);

		if ( ! empty( $attachment_id ) ) {
			return (int) $attachment_id;
		}

		$like          = '%/' . $wpdb->esc_like( $slug ) . '.%';
		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type = 'attachment'
				AND post_status = 'inherit'
				AND guid LIKE %s
				LIMIT 1",
				$like
			)
		);

		return ! empty( $attachment_id ) ? (int) $attachment_id : null;
	}

	/**
	 * Auto-assign on image upload.
	 *
	 * @since 2.6.0
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function on_image_upload( $attachment_id ) {
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return;
		}

		$this->clear_attachment_slug_cache();

		$attachment = get_post( $attachment_id );
		if ( ! $attachment instanceof WP_Post || empty( $attachment->post_name ) ) {
			return;
		}

		$posts = get_posts(
			array(
				'name'           => $attachment->post_name,
				'post_type'      => $this->get_supported_post_types(),
				'post_status'    => array( 'publish', 'draft', 'pending', 'future' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		if ( empty( $posts ) ) {
			return;
		}

		$post_id = (int) $posts[0];
		if ( ! has_post_thumbnail( $post_id ) ) {
			set_post_thumbnail( $post_id, $attachment_id );
		}
	}

	/**
	 * Clear attachment slug cache transient.
	 *
	 * @since 2.6.0
	 * @return void
	 */
	public function clear_attachment_slug_cache() {
		delete_transient( self::ATTACHMENT_MAP_CACHE_KEY );
	}

	/**
	 * Get safe batch size for post processing.
	 *
	 * @since 2.6.0
	 * @return int
	 */
	private function get_batch_size() {
		/**
		 * Filter batch size used for featured image assignment runs.
		 *
		 * @since 2.6.0
		 * @param int $batch_size Batch size.
		 */
		$batch_size = (int) apply_filters( 'sim_fiaa_batch_size', self::DEFAULT_BATCH_SIZE );
		if ( $batch_size < 25 ) {
			return 25;
		}

		if ( $batch_size > 1000 ) {
			return 1000;
		}

		return $batch_size;
	}

	/**
	 * Run scheduled assignment job via WP-Cron.
	 *
	 * @since 2.6.0
	 * @return void
	 */
	public function run_scheduled_assignment() {
		if ( ! get_option( 'sim_fiaa_cron_enabled', 1 ) ) {
			return;
		}

		$raw_post_types = (string) get_option( 'sim_fiaa_cron_post_types', 'post' );
		$post_types     = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', $raw_post_types ) ) ) );

		if ( empty( $post_types ) ) {
			$post_types = array( 'post' );
		}

		$supported_post_types = $this->get_supported_post_types();
		$post_types           = array_values( array_intersect( $post_types, $supported_post_types ) );
		if ( empty( $post_types ) ) {
			return;
		}

		$overwrite   = (bool) get_option( 'sim_fiaa_cron_overwrite', 0 );
		$started_at  = microtime( true );
		$all_results = array(
			'matched'   => 0,
			'skipped'   => 0,
			'unmatched' => 0,
			'total'     => 0,
		);

		foreach ( $post_types as $post_type ) {
			$results                 = $this->run_matcher( $post_type, $overwrite );
			$all_results['matched'] += count( $results['matched'] );
			$all_results['skipped'] += count( $results['skipped'] );
			$all_results['unmatched'] += count( $results['unmatched'] );
			$all_results['total']   += (int) $results['total'];
		}

		$summary = array(
			'ran_at'        => current_time( 'mysql' ),
			'post_types'    => $post_types,
			'overwrite'     => $overwrite ? 1 : 0,
			'matched'       => $all_results['matched'],
			'skipped'       => $all_results['skipped'],
			'unmatched'     => $all_results['unmatched'],
			'total'         => $all_results['total'],
			'duration_ms'   => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
		);

		update_option( 'sim_fiaa_last_run_summary', $summary, false );
	}

	/**
	 * Get supported post types.
	 *
	 * @since 2.6.0
	 * @return array<int,string>
	 */
	private function get_supported_post_types() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		return array_values( $types );
	}

	/**
	 * Render results table.
	 *
	 * @since 2.6.0
	 * @param array<string,mixed> $results Match summary.
	 * @return void
	 */
	private function render_results( $results ) {
		$matched   = isset( $results['matched'] ) && is_array( $results['matched'] ) ? $results['matched'] : array();
		$skipped   = isset( $results['skipped'] ) && is_array( $results['skipped'] ) ? $results['skipped'] : array();
		$unmatched = isset( $results['unmatched'] ) && is_array( $results['unmatched'] ) ? $results['unmatched'] : array();
		$total     = isset( $results['total'] ) ? (int) $results['total'] : 0;

		echo '<p><strong>' . esc_html__( 'Total processed:', 'smart-image-matcher' ) . '</strong> ' . esc_html( (string) $total ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Matched:', 'smart-image-matcher' ) . '</strong> ' . esc_html( (string) count( $matched ) ) . ' | ';
		echo '<strong>' . esc_html__( 'Unmatched:', 'smart-image-matcher' ) . '</strong> ' . esc_html( (string) count( $unmatched ) ) . ' | ';
		echo '<strong>' . esc_html__( 'Skipped:', 'smart-image-matcher' ) . '</strong> ' . esc_html( (string) count( $skipped ) ) . '</p>';

		if ( ! empty( $matched ) ) {
			echo '<h3>' . esc_html__( 'Matched', 'smart-image-matcher' ) . '</h3>';
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Post', 'smart-image-matcher' ) . '</th><th>' . esc_html__( 'Slug', 'smart-image-matcher' ) . '</th><th>' . esc_html__( 'Attachment ID', 'smart-image-matcher' ) . '</th></tr></thead><tbody>';
			foreach ( $matched as $item ) {
				$post_id = isset( $item['id'] ) ? (int) $item['id'] : 0;
				echo '<tr><td><a href="' . esc_url( get_edit_post_link( $post_id ) ) . '">' . esc_html( (string) $item['title'] ) . '</a></td><td><code>' . esc_html( (string) $item['slug'] ) . '</code></td><td>' . esc_html( (string) (int) $item['attachment_id'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		if ( ! empty( $unmatched ) ) {
			echo '<h3>' . esc_html__( 'Unmatched', 'smart-image-matcher' ) . '</h3>';
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Post', 'smart-image-matcher' ) . '</th><th>' . esc_html__( 'Slug', 'smart-image-matcher' ) . '</th><th>' . esc_html__( 'Reason', 'smart-image-matcher' ) . '</th></tr></thead><tbody>';
			foreach ( $unmatched as $item ) {
				$post_id = isset( $item['id'] ) ? (int) $item['id'] : 0;
				echo '<tr><td><a href="' . esc_url( get_edit_post_link( $post_id ) ) . '">' . esc_html( (string) $item['title'] ) . '</a></td><td><code>' . esc_html( (string) $item['slug'] ) . '</code></td><td>' . esc_html( (string) $item['reason'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * Render quick post stats.
	 *
	 * @since 2.6.0
	 * @return void
	 */
	private function render_stats() {
		global $wpdb;

		$total_posts = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			WHERE post_type = 'post'
			AND post_status IN ('publish', 'draft')"
		);

		$with_thumbnail = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_thumbnail_id'
			WHERE p.post_type = 'post'
			AND p.post_status IN ('publish', 'draft')"
		);

		$without = $total_posts - $with_thumbnail;

		echo '<h2>' . esc_html__( 'Post Coverage', 'smart-image-matcher' ) . '</h2>';
		echo '<p>' . esc_html__( 'Total posts:', 'smart-image-matcher' ) . ' <strong>' . esc_html( (string) $total_posts ) . '</strong> | ';
		echo esc_html__( 'With featured image:', 'smart-image-matcher' ) . ' <strong>' . esc_html( (string) $with_thumbnail ) . '</strong> | ';
		echo esc_html__( 'Missing featured image:', 'smart-image-matcher' ) . ' <strong>' . esc_html( (string) $without ) . '</strong></p>';
	}
}

