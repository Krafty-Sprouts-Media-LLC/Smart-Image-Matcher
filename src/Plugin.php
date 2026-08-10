<?php
/**
 * Plugin bootstrap.
 *
 * Owns activation, deactivation, service registration, and hook wiring.
 * Called once from smart-image-matcher.php.
 *
 * @package SmartImageMatcher
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\Admin\GenerateImagesBulkAction;
use SmartImageMatcher\Abilities\Registry as AbilitiesRegistry;
use SmartImageMatcher\Cache\Cache;
use SmartImageMatcher\Domain\Matcher;
use SmartImageMatcher\Domain\Normalizer;
use SmartImageMatcher\Domain\HeadingExtractor;
use SmartImageMatcher\Domain\ImageRepository;
use SmartImageMatcher\Domain\MatchRepository;
use SmartImageMatcher\FeaturedImages\FeaturedImageService;
use SmartImageMatcher\FeaturedImages\SlugMapBuilder;
use SmartImageMatcher\Insertion\BlockBuilder;
use SmartImageMatcher\Insertion\HeadingLocator;
use SmartImageMatcher\Insertion\InsertionService;
use SmartImageMatcher\Logging\Logger;
use SmartImageMatcher\Queue\JobRunner;
use SmartImageMatcher\Queue\Queue;
use SmartImageMatcher\REST\MatchController;
use SmartImageMatcher\REST\InsertController;
use SmartImageMatcher\REST\FeaturedImageController;
use SmartImageMatcher\REST\BulkController;
use SmartImageMatcher\REST\ImageGenController;
use SmartImageMatcher\Domain\PostStatuses;
use SmartImageMatcher\Settings\Settings;
use SmartImageMatcher\Update\GitHubUpdater;
use SmartImageMatcher\Compat\WpAiFeaturedImageCompat;

/**
 * Class Plugin
 *
 * @since 3.0.0
 */
class Plugin {

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @var string
	 */
	private string $file;

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Constructor.
	 *
	 * @param string $file Absolute path to smart-image-matcher.php.
	 */
	public function __construct( string $file ) {
		$this->file      = $file;
		$this->container = new Container();
	}

	/**
	 * Register hooks and boot the plugin.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function boot(): void {
		register_activation_hook( $this->file, array( $this, 'activate' ) );
		register_deactivation_hook( $this->file, array( $this, 'deactivate' ) );

		add_action( 'plugins_loaded', array( $this, 'init' ), 5 );
	}

	/**
	 * Initialise services after all plugins are loaded.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function init(): void {
		// Run database migrations before any service that reads the schema.
		( new Migrator() )->maybeRun();

		// Register features.
		$this->registerFeatures();

		// Bind services into the container.
		$this->bindServices();

		// Wire hooks.
		$this->registerHooks();

		// GitHub → WP automatic updates (public releases).
		( new GitHubUpdater() )->register();
	}

	/**
	 * Plugin activation.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function activate(): void {
		// Minimum WordPress version check.
		if ( version_compare( get_bloginfo( 'version' ), '6.0', '<' ) ) {
			wp_die(
				esc_html__( 'Smart Image Matcher requires WordPress 6.0 or higher.', 'smart-image-matcher' )
			);
		}

		// Minimum PHP version check.
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			wp_die(
				esc_html__( 'Smart Image Matcher requires PHP 7.4 or higher.', 'smart-image-matcher' )
			);
		}

		// OpenSSL check (required by ProviderBridge and any future encryption).
		if ( ! extension_loaded( 'openssl' ) ) {
			wp_die(
				esc_html__( 'Smart Image Matcher requires the PHP OpenSSL extension.', 'smart-image-matcher' )
			);
		}

		// Create / upgrade tables.
		( new Migrator() )->runOnActivation();

		// Write default settings (only adds, never overwrites).
		Settings::writeDefaults();

		// Schedule cleanup cron.
		if ( ! wp_next_scheduled( 'smart_image_matcher_daily_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'smart_image_matcher_daily_cleanup' );
		}

		// Schedule the one-shot inverted-index backfill via Action Scheduler.
		// Will only run once; subsequent activations are no-ops due to the
		// as_has_scheduled_action check inside scheduleIndexBackfill().
		( new Queue() )->scheduleIndexBackfill();

		// No flush_rewrite_rules() — this plugin adds no rewrite rules.
	}

	/**
	 * Plugin deactivation.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function deactivate(): void {
		wp_clear_scheduled_hook( 'smart_image_matcher_daily_cleanup' );
		wp_clear_scheduled_hook( 'smart_image_matcher_fiaa_cron_run' );
		wp_clear_scheduled_hook( Premium\FiaaCron::HOOK );

		if ( Queue::isAvailable() && function_exists( 'as_unschedule_all_actions' ) ) {
			$hooks = array(
				Queue::HOOK_AI_MATCH,
				Queue::HOOK_INDEX_BACKFILL,
				Queue::HOOK_BULK_MATCH,
				Queue::HOOK_BULK_INSERT,
				Queue::HOOK_FIAA_RUN,
				Queue::HOOK_FIAA_AUDIT_CLEAR,
				Queue::HOOK_AI_IMAGE_GEN,
				Queue::HOOK_AI_IMAGE_GEN_POLL,
				Queue::HOOK_FAL_RECOVER,
				Premium\FiaaCron::HOOK,
			);

			foreach ( $hooks as $hook ) {
				as_unschedule_all_actions( $hook, array(), Queue::GROUP );
			}
		}

		// Also clear any orphaned actions left under the pre-rename sim_*
		// hook names so they don't keep failing while the plugin is off.
		Queue::clearLegacyHooks( Migrator::LEGACY_ACTION_HOOKS );

		Cache::clearAll();
	}

	// -------------------------------------------------------------------------
	// Internal wiring
	// -------------------------------------------------------------------------

	/**
	 * Register feature flags.
	 *
	 * All features are fully enabled in the wp.org build — no functionality
	 * is locked or gated (Guideline 5). A separate add-on plugin (hosted
	 * elsewhere) may call Premium::enable($slug) in the future.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	private function registerFeatures(): void {
		// All features are fully enabled in the wp.org build (Guideline 5).
		// A separate Pro add-on plugin may register additional features via
		// Premium::enable() in the future.
		$features = array(
			'ai_matching',
			'ai_alt_text',
			'ai_vision_match',
			'ai_featured_image',
			'ai_image_generation',
			'bulk_processor',
			'review_queue',
			'analytics',
			'auto_match_on_publish',
			'fiaa_scheduled_cron',
			'fiaa_arbitrary_post_types',
			'extended_carousel',
			'cli_commands',
			'rest_premium_endpoints',
		);

		foreach ( $features as $slug ) {
			Premium::registerFeature( $slug, array( 'default' => true ) );
		}
	}

	/**
	 * Bind service factories into the container.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	private function bindServices(): void {
		$c = $this->container;

		// Domain.
		$c->bind( 'normalizer', static fn() => new Normalizer() );
		$c->bind( 'heading.extractor', static fn() => new HeadingExtractor() );
		$c->bind( 'image.repository', static fn() => new ImageRepository() );
		$c->bind( 'match.repository', static fn() => new MatchRepository() );
		$c->bind( 'matcher', static fn( Container $c ) => new Matcher() );

		// Insertion.
		$c->bind( 'heading.locator', static fn() => new HeadingLocator() );
		$c->bind( 'block.builder', static fn() => new BlockBuilder() );
		$c->bind(
			'insertion.service',
			static fn( Container $c ) => new InsertionService(
				$c->get( 'block.builder' )
			)
		);

		// Featured Images.
		$c->bind( 'slug.map.builder', static fn() => new SlugMapBuilder() );
		$c->bind(
			'featured.image.service',
			static fn( Container $c ) => new FeaturedImageService(
				$c->get( 'slug.map.builder' )
			)
		);

		// Queue.
		$c->bind( 'queue', static fn() => new Queue() );
		$c->bind( 'job.runner', static fn() => new JobRunner() );

		// REST controllers.
		$c->bind( 'rest.match', static fn() => new MatchController() );
		$c->bind( 'rest.insert', static fn() => new InsertController() );
		$c->bind( 'rest.featured_image', static fn() => new FeaturedImageController() );
		$c->bind( 'rest.bulk', static fn() => new BulkController() );
		$c->bind( 'rest.image_gen', static fn() => new ImageGenController() );
		$c->bind( 'ai.prompt_builder', static fn() => new AI\PromptBuilder() );
		$c->bind(
			'ai.image_generator',
			static fn( Container $c ) => new Premium\AiImageGenerator(
				$c->get( 'ai.prompt_builder' )
			)
		);
	}

	/**
	 * Register all action and filter hooks.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	private function registerHooks(): void {
		// i18n already loaded in init().

		// Settings page (admin only).
		if ( is_admin() ) {
			$settings = new Settings();
			add_action( 'admin_menu', array( $settings, 'registerMenus' ) );
			add_action( 'admin_init', array( $settings, 'register' ) );
			add_action( 'admin_init', array( $settings, 'redirectLegacyGenerateImagesPage' ), 1 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAdminAssets' ) );
			add_action( 'admin_footer', array( $this, 'renderModal' ) );

			( new GenerateImagesBulkAction() )->register();
		}

		// REST API.
		add_action( 'rest_api_init', array( $this, 'registerRestRoutes' ) );
		add_action(
			'rest_api_init',
			static function () {
				// Suppress DB error output during REST requests.
				// Errors still go to the debug log; they just don't corrupt the JSON response.
				global $wpdb;
				$wpdb->hide_errors();
			},
			1
		);

		// Action Scheduler job hooks.
		Queue::registerHooks();

		// Inverted index — keep in sync with the media library.
		$imageRepo = $this->container->get( 'image.repository' );
		add_action( 'add_attachment', array( $imageRepo, 'indexImage' ) );
		add_action( 'edit_attachment', array( $imageRepo, 'indexImage' ) );
		add_action( 'delete_attachment', array( $imageRepo, 'removeImage' ) );

		// Abilities API (WP 6.9+).
		( new AbilitiesRegistry() )->register();

		// Featured Images — upload-time auto-assign.
		$this->container->get( 'featured.image.service' )->register();

		// Align WP AI “Generate featured image” imports with SIM title/slug/parent rules.
		( new WpAiFeaturedImageCompat() )->register();

		// Bulk Processor + Review Queue.
		( new Premium\BulkProcessor() )->register();
		( new Premium\ReviewQueue() )->register();

		// Scheduled FIAA automation.
		( new Premium\FiaaCron() )->register();

		// AI matching.
		( new Premium\AiMatcher() )->register();

		// AI alt-text generation.
		( new Premium\AiAltText() )->register();

		// Auto-generate featured image on publish (when enabled).
		( new Premium\AutoMatchOnPublish() )->register();

		// Cleanup cron.
		add_action( 'smart_image_matcher_daily_cleanup', array( $this, 'runDailyCleanup' ) );

		// WP-CLI.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'sim', CLI\Commands::class );
		}
	}

	/**
	 * Register REST API routes.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function registerRestRoutes(): void {
		$this->container->get( 'rest.match' )->registerRoutes();
		$this->container->get( 'rest.insert' )->registerRoutes();
		$this->container->get( 'rest.featured_image' )->registerRoutes();
		$this->container->get( 'rest.image_gen' )->registerRoutes();

		if ( Premium::has( 'rest_premium_endpoints' ) ) {
			$this->container->get( 'rest.bulk' )->registerRoutes();
		}
	}

	/**
	 * Enqueue admin assets conditionally per screen.
	 *
	 * @since 3.0.0
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueueAdminAssets( string $hook ): void {
		// Always-loaded: menu icon styles only.
		wp_enqueue_style(
			'smart-image-matcher-admin',
			SMART_IMAGE_MATCHER_PLUGIN_URL . 'admin/css/sim-admin.css',
			array(),
			SMART_IMAGE_MATCHER_VERSION
		);

		if ( false !== strpos( $hook, 'smart-image-matcher' ) ) {
			wp_enqueue_style(
				'smart-image-matcher-pages',
				SMART_IMAGE_MATCHER_PLUGIN_URL . 'admin/css/sim-pages.css',
				array( 'smart-image-matcher-admin' ),
				filemtime( SMART_IMAGE_MATCHER_PLUGIN_DIR . 'admin/css/sim-pages.css' )
			);
		}

		// Post edit screens — modal.
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			wp_enqueue_style(
				'smart-image-matcher-modal-css',
				SMART_IMAGE_MATCHER_PLUGIN_URL . 'admin/css/sim-modal.css',
				array(),
				SMART_IMAGE_MATCHER_VERSION
			);

			// Phase 2: svg-icons.js must load before modal.js.
			wp_enqueue_script(
				'smart-image-matcher-svg-icons',
				SMART_IMAGE_MATCHER_PLUGIN_URL . 'admin/js/src/svg-icons.js',
				array(),
				SMART_IMAGE_MATCHER_VERSION,
				true
			);

			wp_enqueue_script(
				'smart-image-matcher-modal',
				SMART_IMAGE_MATCHER_PLUGIN_URL . 'admin/js/src/modal.js',
				array( 'smart-image-matcher-svg-icons' ),
				SMART_IMAGE_MATCHER_VERSION,
				true
			);

			// Gutenberg sidebar + client-side Abilities.
			wp_enqueue_script(
				'smart-image-matcher-gutenberg',
				SMART_IMAGE_MATCHER_PLUGIN_URL . 'admin/js/src/gutenberg.js',
				array(
					'wp-plugins',
					'wp-editor',
					'wp-element',
					'wp-components',
					'wp-block-editor',
					'wp-i18n',
					'wp-data',
					'smart-image-matcher-svg-icons',
				),
				SMART_IMAGE_MATCHER_VERSION,
				true
			);

			wp_enqueue_style(
				'smart-image-matcher-gutenberg-css',
				SMART_IMAGE_MATCHER_PLUGIN_URL . 'admin/css/sim-gutenberg.css',
				array(),
				SMART_IMAGE_MATCHER_VERSION
			);

			wp_localize_script(
				'smart-image-matcher-modal',
				'smartImageMatcherData',
				array(
					'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
					'nonces'       => array(
						// REST API uses the wp_rest nonce in X-WP-Nonce header.
						'wpRest' => wp_create_nonce( 'wp_rest' ),
					),
					'postId'       => get_the_ID() ?: 0,
					'debug'        => (bool) Settings::get( 'debug_mode' ),
					'features'     => array(
						'aiImageGeneration' => (bool) Settings::get( 'ai_image_generation_enabled' )
							&& \SmartImageMatcher\AI\ProviderBridge::isImageGenerationAvailable(),
					),
					'aiImageStyle' => (string) Settings::get( 'ai_image_style' ),
				)
			);
		}

		// Featured Images screen — queued FIAA manual run monitor.
		if ( false !== strpos( $hook, 'smart-image-matcher-featured-images' ) ) {
			wp_enqueue_script(
				'smart-image-matcher-featured-images',
				SMART_IMAGE_MATCHER_PLUGIN_URL . 'admin/js/src/featured-images.js',
				array( 'wp-api-fetch' ),
				SMART_IMAGE_MATCHER_VERSION,
				true
			);

			wp_localize_script(
				'smart-image-matcher-featured-images',
				'smartImageMatcherFiaa',
				array(
					'nonce'              => wp_create_nonce( 'wp_rest' ),
					'savedSettings'      => array(
						'post_type'       => sanitize_key( (string) Settings::get( 'fiaa_manual_post_type' ) ),
						'post_statuses'   => PostStatuses::sanitizeList( (string) Settings::get( 'fiaa_manual_post_statuses' ) ),
						'featured_filter' => (string) Settings::get( 'fiaa_manual_featured_filter' ),
						'max_posts'       => max( 1, min( 50000, (int) Settings::get( 'fiaa_manual_max_posts' ) ) ),
						'overwrite'       => (bool) Settings::get( 'fiaa_manual_overwrite' ),
					),
					'excludedImageSlugs' => (string) Settings::get( 'fiaa_excluded_image_slugs' ),
					'i18n'               => array(
						'starting'         => __( 'Starting Match Runner...', 'smart-image-matcher' ),
						'running'          => __( 'Running...', 'smart-image-matcher' ),
						'queued'           => __( 'Queued', 'smart-image-matcher' ),
						'processing'       => __( 'Processing', 'smart-image-matcher' ),
						'completed'        => __( 'Run complete.', 'smart-image-matcher' ),
						'cancelled'        => __( 'Run cancelled.', 'smart-image-matcher' ),
						'failed'           => __( 'Run failed.', 'smart-image-matcher' ),
						/* translators: 1: processed count, 2: total count, 3: matched count, 4: skipped count, 5: unmatched count */
						'progress'         => __( 'Processed %1$d of %2$d posts. Matched: %3$d | Skipped: %4$d | Unmatched: %5$d', 'smart-image-matcher' ),
						/* translators: 1: matched count, 2: skipped count, 3: unmatched count */
						'summary'          => __( 'Matched: %1$d | Skipped: %2$d | Unmatched: %3$d', 'smart-image-matcher' ),
						'stalled'          => __( 'The job is still queued. Action Scheduler has not picked it up yet.', 'smart-image-matcher' ),
						'noApi'            => __( 'Match Runner controls could not load because wp.apiFetch is unavailable.', 'smart-image-matcher' ),
						'saving'           => __( 'Saving run settings...', 'smart-image-matcher' ),
						'saved'            => __( 'Run settings saved.', 'smart-image-matcher' ),
						'saveFailed'       => __( 'Could not save run settings.', 'smart-image-matcher' ),
						'noStatuses'       => __( 'Select at least one post status before running.', 'smart-image-matcher' ),
						'exclusionsSaving' => __( 'Saving exclusions...', 'smart-image-matcher' ),
						'exclusionsSaved'  => __( 'Excluded image filenames saved.', 'smart-image-matcher' ),
						'exclusionsFailed' => __( 'Could not save excluded image filenames.', 'smart-image-matcher' ),
						'auditScanning'    => __( 'Scanning featured images...', 'smart-image-matcher' ),
						'auditScanFailed'  => __( 'Could not scan featured images.', 'smart-image-matcher' ),
						/* translators: 1: unsafe count, 2: total assigned count, 3: safe count */
						'auditScanSummary' => __( 'Found %1$d unsafe featured image(s) out of %2$d assigned posts (%3$d safe).', 'smart-image-matcher' ),
						'auditNoneFound'   => __( 'No unsafe featured images were found.', 'smart-image-matcher' ),
						/* translators: 1: preview row count, 2: total unsafe count */
						'auditPreviewNote' => __( 'Showing the first %1$d of %2$d unsafe posts.', 'smart-image-matcher' ),
						'auditStarting'    => __( 'Starting cleanup...', 'smart-image-matcher' ),
						/* translators: 1: processed count, 2: total count, 3: cleared count, 4: skipped count, 5: error count */
						'auditProgress'    => __( 'Processed %1$d of %2$d posts. Cleared: %3$d | Skipped: %4$d | Errors: %5$d', 'smart-image-matcher' ),
						/* translators: 1: cleared count, 2: skipped count, 3: error count */
						'auditSummary'     => __( 'Cleared: %1$d | Skipped: %2$d | Errors: %3$d', 'smart-image-matcher' ),
						'auditCompleted'   => __( 'Cleanup complete.', 'smart-image-matcher' ),
						'auditClearing'    => __( 'Clearing...', 'smart-image-matcher' ),
					),
				)
			);

			$prefill_ids = array();
			if ( isset( $_GET['post_ids'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only prefill for localized config.
				$raw_ids     = sanitize_text_field( wp_unslash( (string) $_GET['post_ids'] ) );
				$prefill_ids = array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) );
			}

			$focus_ai = isset( $_GET['sim_ai'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_GET['sim_ai'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			wp_enqueue_script(
				'smart-image-matcher-generate-images',
				SMART_IMAGE_MATCHER_PLUGIN_URL . 'admin/js/src/generate-images.js',
				array( 'wp-api-fetch' ),
				SMART_IMAGE_MATCHER_VERSION,
				true
			);

			wp_localize_script(
				'smart-image-matcher-generate-images',
				'smartImageMatcherGenerateImages',
				array(
					'nonce'           => wp_create_nonce( 'wp_rest' ),
					'prefillPostIds'  => $prefill_ids,
					'focusAi'         => $focus_ai,
					'generationReady' => (bool) Settings::get( 'ai_image_generation_enabled' )
						&& \SmartImageMatcher\AI\ProviderBridge::isImageGenerationAvailable(),
					'editPostUrl'     => admin_url( 'post.php?post=%d&action=edit' ),
					'i18n'            => $this->featuredAiGenerateI18n(),
				)
			);
		}

		// Posts list — featured-AI bulk modal + sticky progress dock (resume after dismiss/pagination).
		if ( 'edit.php' === $hook ) {
			$auto_open = isset( $_GET['sim_featured_ai'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_GET['sim_featured_ai'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$bulk_ids  = array();
			if ( isset( $_GET['sim_featured_ids'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$bulk_ids = array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( (string) $_GET['sim_featured_ids'] ) ) ) ) );
			}

			$list_post_type = 'post';
			if ( isset( $_GET['post_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$list_post_type = sanitize_key( wp_unslash( (string) $_GET['post_type'] ) );
			}
			if ( ! post_type_exists( $list_post_type ) ) {
				$list_post_type = 'post';
			}

			wp_enqueue_style(
				'smart-image-matcher-featured-ai-modal',
				SMART_IMAGE_MATCHER_PLUGIN_URL . 'admin/css/sim-featured-ai-modal.css',
				array(),
				SMART_IMAGE_MATCHER_VERSION
			);

			wp_enqueue_script(
				'smart-image-matcher-featured-ai-bulk-modal',
				SMART_IMAGE_MATCHER_PLUGIN_URL . 'admin/js/src/featured-ai-bulk-modal.js',
				array( 'wp-api-fetch' ),
				SMART_IMAGE_MATCHER_VERSION,
				true
			);

			wp_localize_script(
				'smart-image-matcher-featured-ai-bulk-modal',
				'smartImageMatcherFeaturedAiBulk',
				array(
					'nonce'           => wp_create_nonce( 'wp_rest' ),
					'postIds'         => array_values( $bulk_ids ),
					'postType'        => $list_post_type,
					'autoOpen'        => $auto_open && ! empty( $bulk_ids ),
					'defaultStyle'    => (string) Settings::get( 'ai_image_style' ),
					'generationReady' => (bool) Settings::get( 'ai_image_generation_enabled' )
						&& \SmartImageMatcher\AI\ProviderBridge::isImageGenerationAvailable(),
					'editPostUrl'     => admin_url( 'post.php?post=%d&action=edit' ),
					'i18n'            => array(
						'title'              => __( 'Generate featured images', 'smart-image-matcher' ),
						'scanning'           => __( 'Scanning selected posts…', 'smart-image-matcher' ),
						'scanFailed'         => __( 'Could not scan posts.', 'smart-image-matcher' ),
						'noResults'          => __( 'None of the selected posts need a featured image.', 'smart-image-matcher' ),
						/* translators: %d: image count */
						'confirmGenerate'    => __( 'Generate %d featured image(s)? Time varies by model — often a few minutes each.', 'smart-image-matcher' ),
						'generating'         => __( 'Queueing jobs…', 'smart-image-matcher' ),
						'generateFailed'     => __( 'Could not queue jobs.', 'smart-image-matcher' ),
						/* translators: %d: number of jobs queued */
						'queuedNotice'       => __( '%d featured image job(s) queued. You can dismiss this dialog; progress stays visible below.', 'smart-image-matcher' ),
						/* translators: 1: succeeded count, 2: failed count */
						'allDone'            => __( 'Finished: %1$d succeeded, %2$d failed. Refresh the posts list to see new featured images.', 'smart-image-matcher' ),
						/* translators: %d: succeeded count */
						'allDoneOk'          => __( 'Finished: %d featured image(s) set. Refresh the posts list to see them.', 'smart-image-matcher' ),
						'estimateHint'       => __( 'Usually a few minutes per image (varies by model and queue).', 'smart-image-matcher' ),
						/* translators: %d: image count */
						'imagesCount'        => __( '%d to generate', 'smart-image-matcher' ),
						/* translators: 1: image count, 2: time hint */
						'estimateWithCount'  => __( '%1$d to generate. %2$s', 'smart-image-matcher' ),
						'dismiss'            => __( 'Dismiss', 'smart-image-matcher' ),
						'generate'           => __( 'Generate', 'smart-image-matcher' ),
						'scan'               => __( 'Scan', 'smart-image-matcher' ),
						'close'              => __( 'Close', 'smart-image-matcher' ),
						'needsFeatured'      => __( 'Missing featured image', 'smart-image-matcher' ),
						'alreadyHasFeatured' => __( 'Already has featured image', 'smart-image-matcher' ),
						'noThumbnailSupport' => __( 'Post type does not support featured images', 'smart-image-matcher' ),
						'alreadyGenerated'   => __( 'Already generated for this style', 'smart-image-matcher' ),
						'rejected'           => __( 'Previously rejected', 'smart-image-matcher' ),
						'notFound'           => __( 'Post not found', 'smart-image-matcher' ),
						'noPermission'       => __( 'No permission', 'smart-image-matcher' ),
						'skippedOther'       => __( 'Skipped', 'smart-image-matcher' ),
						'queued'             => __( 'Queued…', 'smart-image-matcher' ),
						'processing'         => __( 'Generating…', 'smart-image-matcher' ),
						'generated'          => __( 'Featured image set', 'smart-image-matcher' ),
						'failed'             => __( 'Failed', 'smart-image-matcher' ),
						'edit'               => __( 'Edit', 'smart-image-matcher' ),
						'minute'             => __( 'minute', 'smart-image-matcher' ),
						'minutes'            => __( 'minutes', 'smart-image-matcher' ),
						'second'             => __( 'second', 'smart-image-matcher' ),
						'seconds'            => __( 'seconds', 'smart-image-matcher' ),
						/* translators: 1: completed count, 2: total count */
						'progress'           => __( 'Completed %1$d of %2$d', 'smart-image-matcher' ),
						'styleLabel'         => __( 'Style', 'smart-image-matcher' ),
						'stylePhoto'         => __( 'Photo (realistic)', 'smart-image-matcher' ),
						'styleIllustration'  => __( 'Illustration', 'smart-image-matcher' ),
						'noApi'              => __( 'Could not load controls (wp.apiFetch missing).', 'smart-image-matcher' ),
						'notReady'           => __( 'Enable on-demand image generation and connect an image provider under Settings → Connectors first.', 'smart-image-matcher' ),
						'dockTitle'          => __( 'Featured image generation', 'smart-image-matcher' ),
						/* translators: %d: remaining job count */
						'dockRemaining'      => __( '%d remaining', 'smart-image-matcher' ),
						'dockExpand'         => __( 'Show details', 'smart-image-matcher' ),
						'dockCollapse'       => __( 'Hide details', 'smart-image-matcher' ),
						'dockReopen'         => __( 'Open dialog', 'smart-image-matcher' ),
						'dockHide'           => __( 'Hide', 'smart-image-matcher' ),
						'untitled'           => __( 'Untitled', 'smart-image-matcher' ),
					),
				)
			);
		}
	}

	/**
	 * i18n strings for Featured Images AI Generate card.
	 *
	 * @since 3.2.3
	 * @return array<string, string>
	 */
	private function featuredAiGenerateI18n(): array {
		return array(
			'scanning'                     => __( 'Scanning posts…', 'smart-image-matcher' ),
			'scanFailed'                   => __( 'Could not scan posts.', 'smart-image-matcher' ),
			'noStatuses'                   => __( 'Select at least one post status before scanning.', 'smart-image-matcher' ),
			'noResults'                    => __( 'No posts need a featured image for the current filters.', 'smart-image-matcher' ),
			'scanComplete'                 => __( 'Scan complete.', 'smart-image-matcher' ),
			/* translators: %d: image count */
			'confirmGenerate'              => __( 'Generate %d featured image(s)? Time varies by model — often a few minutes each.', 'smart-image-matcher' ),
			'generating'                   => __( 'Queueing generation jobs…', 'smart-image-matcher' ),
			'generateFailed'               => __( 'Could not queue generation jobs.', 'smart-image-matcher' ),
			'generateComplete'             => __( 'All jobs finished.', 'smart-image-matcher' ),
			/* translators: 1: completed count, 2: total count */
			'progress'                     => __( 'Completed %1$d of %2$d', 'smart-image-matcher' ),
			'noApi'                        => __( 'Generate Featured Images controls could not load because wp.apiFetch is unavailable.', 'smart-image-matcher' ),
			'minute'                       => __( 'minute', 'smart-image-matcher' ),
			'minutes'                      => __( 'minutes', 'smart-image-matcher' ),
			'second'                       => __( 'second', 'smart-image-matcher' ),
			'seconds'                      => __( 'seconds', 'smart-image-matcher' ),
			'queued'                       => __( 'Queued', 'smart-image-matcher' ),
			'processing'                   => __( 'Processing', 'smart-image-matcher' ),
			'completed'                    => __( 'Completed', 'smart-image-matcher' ),
			'failed'                       => __( 'Failed', 'smart-image-matcher' ),
			'needsFeatured'                => __( 'Missing featured image', 'smart-image-matcher' ),
			'alreadyHasFeatured'           => __( 'Already has featured image', 'smart-image-matcher' ),
			'noThumbnailSupport'           => __( 'Post type does not support featured images', 'smart-image-matcher' ),
			'alreadyGenerated'             => __( 'Already generated for this style', 'smart-image-matcher' ),
			'rejected'                     => __( 'Previously rejected', 'smart-image-matcher' ),
			'notFound'                     => __( 'Post not found', 'smart-image-matcher' ),
			'noPermission'                 => __( 'No permission', 'smart-image-matcher' ),
			'skippedOther'                 => __( 'Skipped', 'smart-image-matcher' ),
			'estimateHint'                 => __( 'Usually a few minutes per image (varies by model and queue).', 'smart-image-matcher' ),
			/* translators: %d: image count */
			'imagesCount'                  => __( '%d to generate', 'smart-image-matcher' ),
			'edit'                         => __( 'Edit', 'smart-image-matcher' ),
			'recoveryPreviewing'           => __( 'Checking recent completed fal.ai images…', 'smart-image-matcher' ),
			'recoveryPreviewFailed'        => __( 'Could not preview fal.ai recovery.', 'smart-image-matcher' ),
			'recoveryCriticalError'        => __( 'WordPress hit a critical error while previewing recovery. Update AI Provider for fal.ai to 1.1.11+, then try again. If it still fails, check wp-content/debug.log.', 'smart-image-matcher' ),
			/* translators: 1: matched count, 2: unmatched count */
			'recoveryPreviewComplete'      => __( '%1$d safe match(es) found; %2$d will remain untouched.', 'smart-image-matcher' ),
			'noRecoveryMatches'            => __( 'No safe matches were found. Nothing will be imported.', 'smart-image-matcher' ),
			/* translators: %d: matched image count */
			'confirmRecovery'              => __( 'Recover %d matched image(s) into WordPress? Unmatched images will not be imported.', 'smart-image-matcher' ),
			'recoveryQueueing'             => __( 'Queueing matched images for background recovery…', 'smart-image-matcher' ),
			'recoveryQueueFailed'          => __( 'Could not queue fal.ai recovery.', 'smart-image-matcher' ),
			/* translators: %d: queued image count */
			'recoveryQueued'               => __( '%d image(s) queued for recovery.', 'smart-image-matcher' ),
			/* translators: 1: recovered count, 2: total count */
			'recoveryProgress'             => __( 'Recovered %1$d of %2$d', 'smart-image-matcher' ),
			'recoveryComplete'             => __( 'All matched images were recovered.', 'smart-image-matcher' ),
			/* translators: %d: failed image count */
			'recoveryCompleteWithFailures' => __( 'Recovery finished with %d failure(s).', 'smart-image-matcher' ),
			'recoveryMatched'              => __( 'Safe match', 'smart-image-matcher' ),
			'recoveryUnmatched'            => __( 'Not matched', 'smart-image-matcher' ),
			'recovering'                   => __( 'Recovering', 'smart-image-matcher' ),
		);
	}

	/**
	 * Render the modal HTML into admin_footer on post-edit screens.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function renderModal(): void {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'post', 'page' ), true ) ) {
			return;
		}
		?>
		<div id="sim-modal" class="sim-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="sim-modal-title">
			<div class="sim-modal-overlay"></div>
			<div class="sim-modal-content">
				<div class="sim-modal-header">
					<h2 id="sim-modal-title"><?php esc_html_e( 'Smart Image Matcher', 'smart-image-matcher' ); ?></h2>
					<button type="button" class="sim-modal-close" aria-label="<?php esc_attr_e( 'Close', 'smart-image-matcher' ); ?>">&times;</button>
				</div>

				<div class="sim-modal-body">
					<!-- Loading state -->
					<div class="sim-loading-state">
						<p><?php esc_html_e( 'Analysing content…', 'smart-image-matcher' ); ?></p>
						<div class="sim-progress-bar"><div class="sim-progress-fill"></div></div>
						<p class="sim-loading-info"></p>
					</div>

					<!-- Results state -->
					<div class="sim-results-state" style="display:none;">
						<div class="sim-results-summary"></div>
						<div class="sim-matches-container"></div>
					</div>

					<!-- Progress state (during insert) -->
					<div class="sim-progress-state" style="display:none;">
						<p class="sim-progress-info"></p>
					</div>

					<!-- Error state -->
					<div class="sim-error-state" style="display:none;">
						<p class="sim-error-message"></p>
						<button type="button" class="button sim-cancel-button"><?php esc_html_e( 'Close', 'smart-image-matcher' ); ?></button>
					</div>
				</div>

				<div class="sim-modal-footer">
					<button type="button" class="button sim-cancel-button"><?php esc_html_e( 'Cancel', 'smart-image-matcher' ); ?></button>
					<button type="button" class="button sim-generate-all-button" style="display:none;">
						<?php esc_html_e( 'Generate All', 'smart-image-matcher' ); ?>
					</button>
					<button type="button" class="button button-primary sim-insert-all-button" style="display:none;">
						<?php esc_html_e( 'Insert All Selected', 'smart-image-matcher' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
		// Classic Editor only: Block Editor uses the Gutenberg sidebar/panel.
		// Rendering this on block-editor screens caused a top-left FOUC while
		// Gutenberg was still mounting (modal.js used to unhide it too early).
		if ( $this->isClassicEditorScreen( $screen ) ) :
			?>
		<div id="sim-editor-button-container" class="sim-editor-button-container" hidden>
			<button type="button" id="sim-open-modal" class="button button-secondary">
				<?php esc_html_e( 'Smart Image Matcher', 'smart-image-matcher' ); ?>
			</button>
		</div>
			<?php
		endif;
	}

	/**
	 * Whether the current post-edit screen uses the Classic Editor.
	 *
	 * @since 3.0.8
	 * @param \WP_Screen $screen Current admin screen.
	 * @return bool
	 */
	private function isClassicEditorScreen( \WP_Screen $screen ): bool {
		$post_type = $screen->post_type ? $screen->post_type : 'post';

		if ( function_exists( 'use_block_editor_for_post' ) ) {
			$post_id = get_the_ID();
			if ( $post_id ) {
				return ! use_block_editor_for_post( $post_id );
			}
		}

		if ( function_exists( 'use_block_editor_for_post_type' ) ) {
			return ! use_block_editor_for_post_type( $post_type );
		}

		// Very old WP without block-editor helpers — treat as classic.
		return true;
	}

	/**
	 * Daily cleanup cron handler.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function runDailyCleanup(): void {
		global $wpdb;

		$matches = esc_sql( $wpdb->prefix . 'smart_image_matcher_matches' );
		$queue   = esc_sql( $wpdb->prefix . 'smart_image_matcher_queue' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Pending matches abandoned for > 30 days.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$matches} WHERE status = %s AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
				'pending'
			)
		);

		// Rejected matches > 30 days.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$matches} WHERE status = %s AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
				'rejected'
			)
		);

		// Approved matches > 90 days (audit trail past usefulness).
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$matches} WHERE status = %s AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)",
				'approved'
			)
		);

		// Completed queue rows > 7 days.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$queue} WHERE status = %s AND finished_at < DATE_SUB(NOW(), INTERVAL 7 DAY)",
				'completed'
			)
		);

		// Failed queue rows > 30 days.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$queue} WHERE status = %s AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
				'failed'
			)
		);

		// Stuck processing rows > 24 h.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$queue} SET status = %s, error_message = %s
			 WHERE status = %s AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)",
				'failed',
				'Stuck in processing state for more than 24 hours.',
				'processing'
			)
		);
		// phpcs:enable

		// Clear expired plugin transients.
		Cache::clearExpiredTransients();

		Logger::info( 'Daily cleanup complete.' );
	}
}
