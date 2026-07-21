<?php
/**
 * Settings management.
 *
 * All configuration lives in a single smart_image_matcher_settings option (autoload=no).
 * The WordPress Settings API is used for the settings page.
 *
 * @package SmartImageMatcher\Settings
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Settings;

use SmartImageMatcher\Domain\PostStatuses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings
 *
 * @since 3.0.0
 */
class Settings {

	/**
	 * Option name for the consolidated settings bag.
	 */
	const OPTION = 'smart_image_matcher_settings';

	/**
	 * Option name for runtime state (cron summaries etc.).
	 */
	const RUNTIME_OPTION = 'smart_image_matcher_runtime';

	/**
	 * Default values for every free-tier setting.
	 *
	 * @var array<string, mixed>
	 */
	private static array $defaults = array(
		// Matching.
		'match_mode'                 => 'keyword',
		'confidence_threshold'       => 70,
		'hierarchy_mode'             => 'smart',
		'heading_overlap_threshold'  => 70,
		'max_matches_per_heading'    => 3,
		'minimum_image_spacing'      => 300,
		// Linguistics.
		'enable_stemming'            => true,
		'enable_spelling_variants'   => true,
		'whitelisted_short_words'    => 'io',
		// Featured Images (free scope).
		'fiaa_auto_assign_on_upload' => true,
		'fiaa_upload_post_types'     => 'post,page',
		// Premium placeholders (read by premium classes).
		'fiaa_cron_enabled'          => false,
		'fiaa_cron_interval'         => 'daily',
		'fiaa_cron_post_types'       => 'post',
		'fiaa_cron_post_statuses'    => 'publish',
		'fiaa_cron_featured_filter'  => 'missing',
		'fiaa_cron_overwrite'        => false,
		// Manual Featured Image matcher (Featured Images admin page).
		'fiaa_manual_post_type'       => 'post',
		'fiaa_manual_post_statuses'     => 'publish,draft',
		'fiaa_manual_featured_filter'   => 'missing',
		'fiaa_manual_max_posts'         => 5000,
		'fiaa_manual_overwrite'         => false,
		// Developer / misc.
		'debug_mode'                 => false,
		'delete_on_uninstall'        => true,
		// Cache durations (seconds).
		'cache_match_results_duration' => 3600,
		// AI feature controls.
		'ai_alt_text_on_upload'      => false,
		'ai_vision_match_enabled'    => false,
		'ai_featured_image_enabled'  => false,
	);

	// -------------------------------------------------------------------------
	// Read / write API
	// -------------------------------------------------------------------------

	/**
	 * Get a single setting value.
	 *
	 * @since 3.0.0
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? ( self::$defaults[ $key ] ?? null );
	}

	/**
	 * Get all settings merged with defaults.
	 *
	 * @since 3.0.0
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::$defaults, $saved );
	}

	/**
	 * Persist a full settings array (sanitized by caller).
	 *
	 * @since 3.0.0
	 * @param array<string, mixed> $values Sanitized values.
	 * @return void
	 */
	public static function save( array $values ): void {
		update_option( self::OPTION, $values, false );
	}

	/**
	 * Write default values on fresh install.
	 *
	 * Uses add_option — will not overwrite existing values.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public static function writeDefaults(): void {
		// Project targets WP versions where 'no' is the required autoload value.
		// @phpstan-ignore argument.type
		add_option( self::OPTION, self::$defaults, '', 'no' );
		// @phpstan-ignore argument.type
		add_option( self::RUNTIME_OPTION, array(), '', 'no' );
		// @phpstan-ignore argument.type
		add_option( 'smart_image_matcher_db_version', 0, '', 'no' );
	}

	// -------------------------------------------------------------------------
	// WordPress Settings API
	// -------------------------------------------------------------------------

	/**
	 * Register admin menus.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function registerMenus(): void {
		$icon = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAiIGhlaWdodD0iMjAiIHZpZXdCb3g9IjAgMCAyMCAyMCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cGF0aCBkPSJNMiAzQzIgMi40NDc3MiAyLjQ0NzcyIDIgMyAySDE3QzE3LjU1MjMgMiAxOCAyLjQ0NzcyIDE4IDNWMTNDMTggMTMuNTUyMyAxNy41NTIzIDE0IDE3IDE0SDNDMi40NDc3MiAxNCAyIDEzLjU1MjMgMiAxM1YzWiIgc3Ryb2tlPSIjNjY2NjY2IiBzdHJva2Utd2lkdGg9IjEuNSIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBzdHJva2UtbGluZWpvaW49InJvdW5kIi8+PGNpcmNsZSBjeD0iNyIgY3k9IjciIHI9IjEuNSIgZmlsbD0iIzY2NjY2NiIvPjxwYXRoIGQ9Ik0yIDExTDUuNSA4TDkgMTAuNUwxMy41IDZMMTggMTAiIHN0cm9rZT0iIzY2NjY2NiIgc3Ryb2tlLXdpZHRoPSIxLjUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIgc3Ryb2tlLWxpbmVqb2luPSJyb3VuZCIvPjwvc3ZnPg==';

		// Top-level menu — slug matches Settings so clicking SIM opens Settings.
		add_menu_page(
			__( 'Smart Image Matcher', 'smart-image-matcher' ),
			'SIM',
			'edit_posts',
			'smart-image-matcher',
			array( $this, 'renderDashboardPage' ),
			$icon,
			30
		);

		// The first add_submenu_page with the parent slug becomes the default
		// landing page AND the first visible item. We register Settings first
		// so it is the default, then immediately remove the auto-generated
		// duplicate entry WordPress adds, then re-add it at the bottom.
		// This gives us: Featured Images → Bulk Processor → Settings order.

		// Step 1: register Settings as the default (required by WP).
		add_submenu_page(
			'smart-image-matcher',
			__( 'Smart Image Matcher – Settings', 'smart-image-matcher' ),
			__( 'Dashboard', 'smart-image-matcher' ),
			'manage_options',
			'smart-image-matcher',
			array( $this, 'renderDashboardPage' )
		);

		// Step 2: Featured Images.
		add_submenu_page(
			'smart-image-matcher',
			__( 'Smart Image Matcher – Featured Images', 'smart-image-matcher' ),
			__( 'Featured Images', 'smart-image-matcher' ),
			'manage_options',
			'smart-image-matcher-featured-images',
			array( $this, 'renderFeaturedImagesPage' )
		);

		add_submenu_page(
			'smart-image-matcher',
			__( 'Smart Image Matcher - Settings', 'smart-image-matcher' ),
			__( 'Settings', 'smart-image-matcher' ),
			'manage_options',
			'smart-image-matcher-settings',
			array( $this, 'renderSettingsPage' )
		);

		// NOTE: Bulk Processor submenu is registered by Premium\BulkProcessor::registerMenu()
		// when Premium::has('bulk_processor') is true. Do not add it here.

		// Step 3: Move Settings to the bottom of the submenu list.
		// WordPress auto-adds a duplicate of the parent as the first submenu item.
		// We remove it and re-add it at the end so the visual order is:
		//   Featured Images → Bulk Processor → Settings
		add_action( 'admin_menu', array( $this, 'reorderSettingsToBottom' ), 999 );
	}

	/**
	 * Move the Settings submenu item to the bottom of the SIM menu.
	 *
	 * WordPress always places the first registered submenu at the top.
	 * This hook runs at priority 999 (after all submenus are registered)
	 * and moves the Settings entry to the end.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function reorderSettingsToBottom(): void {
		global $submenu;

		if ( empty( $submenu['smart-image-matcher'] ) ) {
			return;
		}

		$items    = $submenu['smart-image-matcher'];
		$settings = null;
		$rest     = array();

		foreach ( $items as $item ) {
			// The Settings item has slug 'smart-image-matcher' (same as parent).
			if ( isset( $item[2] ) && $item[2] === 'smart-image-matcher-settings' ) {
				$settings = $item;
			} else {
				$rest[] = $item;
			}
		}

		if ( $settings ) {
			$rest[] = $settings; // Append Settings at the end.
			$submenu['smart-image-matcher'] = array_values( $rest ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		}
	}

	/**
	 * Register settings, sections, and fields via the WordPress Settings API.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function register(): void {
		register_setting(
			'smart_image_matcher_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitizeOptions' ),
				'default'           => self::$defaults,
			)
		);

		// ---- Matching section ----
		add_settings_section(
			'smart_image_matcher_matching',
			__( 'Matching', 'smart-image-matcher' ),
			static function () {
				echo '<p>' . esc_html__( 'Controls the matches shown in the post editor modal. Higher thresholds show fewer, safer suggestions.', 'smart-image-matcher' ) . '</p>';
			},
			'smart_image_matcher_settings'
		);

		$this->addField( 'smart_image_matcher_matching', 'match_mode', __( 'Default Match Mode', 'smart-image-matcher' ), 'renderMatchMode' );
		$this->addField( 'smart_image_matcher_matching', 'confidence_threshold', __( 'Confidence Threshold (%)', 'smart-image-matcher' ), 'renderConfidenceThreshold' );
		$this->addField( 'smart_image_matcher_matching', 'hierarchy_mode', __( 'Hierarchy Mode', 'smart-image-matcher' ), 'renderHierarchyMode' );
		$this->addField( 'smart_image_matcher_matching', 'heading_overlap_threshold', __( 'Heading Overlap Threshold (%)', 'smart-image-matcher' ), 'renderHeadingOverlapThreshold' );
		$this->addField( 'smart_image_matcher_matching', 'max_matches_per_heading', __( 'Max Matches per Heading', 'smart-image-matcher' ), 'renderMaxMatches' );
		$this->addField( 'smart_image_matcher_matching', 'minimum_image_spacing', __( 'Minimum Image Spacing', 'smart-image-matcher' ), 'renderMinimumImageSpacing' );

		// ---- Performance section ----
		add_settings_section(
			'smart_image_matcher_performance',
			__( 'Performance', 'smart-image-matcher' ),
			static function () {
				echo '<p>' . esc_html__( 'Controls how long unchanged match results are reused. Caching makes repeat scans faster.', 'smart-image-matcher' ) . '</p>';
			},
			'smart_image_matcher_settings'
		);

		$this->addField( 'smart_image_matcher_performance', 'cache_match_results_duration', __( 'Match Cache Duration', 'smart-image-matcher' ), 'renderCacheDuration' );

		// ---- Linguistics section ----
		add_settings_section(
			'smart_image_matcher_linguistics',
			__( 'Linguistic Enhancements', 'smart-image-matcher' ),
			static function () {
				echo '<p>' . esc_html__( 'Helps the matcher understand simple word variations, such as singular/plural forms and spelling differences.', 'smart-image-matcher' ) . '</p>';
			},
			'smart_image_matcher_settings'
		);

		$this->addField( 'smart_image_matcher_linguistics', 'enable_stemming', __( 'Stemming (Singular/Plural)', 'smart-image-matcher' ), 'renderCheckbox' );
		$this->addField( 'smart_image_matcher_linguistics', 'enable_spelling_variants', __( 'Spelling Variants (US/British)', 'smart-image-matcher' ), 'renderCheckbox' );
		$this->addField( 'smart_image_matcher_linguistics', 'whitelisted_short_words', __( 'Whitelisted Short Words', 'smart-image-matcher' ), 'renderShortWords' );

		// ---- Featured Images section (free controls) ----
		add_settings_section(
			'smart_image_matcher_fiaa_free',
			__( 'Featured Image Auto-Assigner', 'smart-image-matcher' ),
			static function () {
				echo '<p>' . esc_html__( 'When a newly uploaded image filename matches a post slug, the plugin can set that image as the post featured image.', 'smart-image-matcher' ) . '</p>';
			},
			'smart_image_matcher_settings'
		);

		$this->addField( 'smart_image_matcher_fiaa_free', 'fiaa_auto_assign_on_upload', __( 'Auto-Assign on Upload', 'smart-image-matcher' ), 'renderCheckbox' );
		$this->addField( 'smart_image_matcher_fiaa_free', 'fiaa_upload_post_types', __( 'Post Types (upload)', 'smart-image-matcher' ), 'renderPostTypeList' );

		// ---- Featured Images scheduled cron section ----
		add_settings_section(
			'smart_image_matcher_fiaa_cron',
			__( 'Scheduled Auto-Assignment', 'smart-image-matcher' ),
			array( $this, 'renderFiaaCronSectionDescription' ),
			'smart_image_matcher_settings'
		);

		$this->addField( 'smart_image_matcher_fiaa_cron', 'fiaa_cron_enabled', __( 'Scheduled Run', 'smart-image-matcher' ), 'renderFiaaCronEnabled' );
		$this->addField( 'smart_image_matcher_fiaa_cron', 'fiaa_cron_interval', __( 'Run Interval', 'smart-image-matcher' ), 'renderFiaaCronInterval' );
		$this->addField( 'smart_image_matcher_fiaa_cron', 'fiaa_cron_post_types', __( 'Post Types (scheduled)', 'smart-image-matcher' ), 'renderFiaaCronPostTypes' );
		$this->addField( 'smart_image_matcher_fiaa_cron', 'fiaa_cron_post_statuses', __( 'Post Statuses (scheduled)', 'smart-image-matcher' ), 'renderFiaaCronPostStatuses' );
		$this->addField( 'smart_image_matcher_fiaa_cron', 'fiaa_cron_featured_filter', __( 'Featured Image Filter', 'smart-image-matcher' ), 'renderFiaaCronFeaturedFilter' );
		$this->addField( 'smart_image_matcher_fiaa_cron', 'fiaa_cron_overwrite', __( 'Overwrite Existing', 'smart-image-matcher' ), 'renderFiaaCronOverwrite' );

		// ---- Developer section ----
		add_settings_section(
			'smart_image_matcher_developer',
			__( 'Developer', 'smart-image-matcher' ),
			'__return_false',
			'smart_image_matcher_settings'
		);

		$this->addField( 'smart_image_matcher_developer', 'debug_mode', __( 'Debug Logging', 'smart-image-matcher' ), 'renderCheckbox' );
		$this->addField( 'smart_image_matcher_developer', 'delete_on_uninstall', __( 'Delete data on uninstall', 'smart-image-matcher' ), 'renderCheckbox' );

		// ---- AI Features section ----
		add_settings_section(
			'smart_image_matcher_ai',
			__( 'AI Features', 'smart-image-matcher' ),
			array( $this, 'renderAiSectionDescription' ),
			'smart_image_matcher_settings'
		);

		$this->addField( 'smart_image_matcher_ai', 'ai_alt_text_on_upload', __( 'Auto-generate alt text on upload', 'smart-image-matcher' ), 'renderAiAltTextToggle' );
		$this->addField( 'smart_image_matcher_ai', 'ai_vision_match_enabled', __( 'Vision-based matching', 'smart-image-matcher' ), 'renderAiVisionToggle' );
		$this->addField( 'smart_image_matcher_ai', 'ai_featured_image_enabled', __( 'Generate featured images (FIAA fallback)', 'smart-image-matcher' ), 'renderAiFeaturedImageToggle' );
	}

	/**
	 * Sanitize the consolidated settings option.
	 *
	 * @since 3.0.8
	 * @param mixed $raw Raw option value from the Settings API.
	 * @return array<string, mixed>
	 */
	public static function sanitizeOptions( $raw ): array {
		return ( new Sanitizer() )->sanitize( $raw );
	}

	// -------------------------------------------------------------------------
	// Page renderers
	// -------------------------------------------------------------------------

	/**
	 * Render the dashboard page.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function renderDashboardPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'smart-image-matcher' ) );
		}
		require SMART_IMAGE_MATCHER_PLUGIN_DIR . 'admin/views/dashboard.php';
	}

	/**
	 * Render the main settings page.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function renderSettingsPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'smart-image-matcher' ) );
		}
		require SMART_IMAGE_MATCHER_PLUGIN_DIR . 'admin/views/settings-page.php';
	}

	/**
	 * Render the Featured Images page.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function renderFeaturedImagesPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'smart-image-matcher' ) );
		}
		require SMART_IMAGE_MATCHER_PLUGIN_DIR . 'admin/views/featured-images.php';
	}

	// -------------------------------------------------------------------------
	// Field renderers
	// -------------------------------------------------------------------------

	/**
	 * Render match mode select.
	 *
	 * Shows AI mode only when ProviderBridge::isAvailable() returns true
	 * (i.e. WP 7.0+ is installed and a provider is configured).
	 *
	 * @since 3.0.0
	 * @param array<string,string> $args Field args.
	 * @return void
	 */
	public function renderMatchMode( array $args ): void {
		$key     = $args['key'];
		$current = self::get( $key );
		$name    = self::OPTION . '[' . esc_attr( $key ) . ']';

		$aiAvailable = \SmartImageMatcher\AI\ProviderBridge::isAvailable();

		$modes = array(
			'keyword' => __( 'Keyword (fast)', 'smart-image-matcher' ),
		);

		if ( $aiAvailable ) {
			$modes['ai'] = __( 'AI via Connectors (accurate)', 'smart-image-matcher' );
		}

		echo '<select name="' . esc_attr( $name ) . '" id="smart_image_matcher_' . esc_attr( $key ) . '">';
		foreach ( $modes as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Keyword mode is fastest. AI mode appears only when a supported AI provider is available.', 'smart-image-matcher' ) . '</p>';

		if ( ! $aiAvailable ) {
			$connectorsUrl = admin_url( 'options-general.php?page=connectors' );
			printf(
				'<p class="description">%s <a href="%s">%s</a></p>',
				esc_html__( 'AI mode requires a configured AI provider.', 'smart-image-matcher' ),
				esc_url( $connectorsUrl ),
				esc_html__( 'Settings → Connectors', 'smart-image-matcher' )
			);
		}
	}

	/**
	 * Render a number input for confidence threshold.
	 *
	 * @since 3.0.0
	 * @param array<string,string> $args Field args.
	 * @return void
	 */
	public function renderConfidenceThreshold( array $args ): void {
		$key   = $args['key'];
		$value = (int) self::get( $key );
		$name  = self::OPTION . '[' . esc_attr( $key ) . ']';

		printf(
			'<input type="number" name="%s" id="smart_image_matcher_%s" value="%s" min="0" max="100" step="1" />',
			esc_attr( $name ),
			esc_attr( $key ),
			esc_attr( (string) $value )
		);
		echo ' <span>%</span><p class="description">' . esc_html__( 'Minimum score (0-100) to show as a match.', 'smart-image-matcher' ) . '</p>';
	}

	/**
	 * Render hierarchy mode select.
	 *
	 * @since 3.0.0
	 * @param array<string,string> $args Field args.
	 * @return void
	 */
	public function renderHierarchyMode( array $args ): void {
		$key     = $args['key'];
		$current = self::get( $key );
		$name    = self::OPTION . '[' . esc_attr( $key ) . ']';

		$options = array(
			'all'     => __( 'All Headings (H2–H6)', 'smart-image-matcher' ),
			'primary' => __( 'Primary Only (H2)', 'smart-image-matcher' ),
			'smart'   => __( 'Smart Hierarchy (recommended)', 'smart-image-matcher' ),
		);

		echo '<select name="' . esc_attr( $name ) . '" id="smart_image_matcher_' . esc_attr( $key ) . '">';
		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Smart Hierarchy reduces duplicate suggestions by focusing on the most useful headings.', 'smart-image-matcher' ) . '</p>';
	}

	/**
	 * Render heading overlap threshold number input.
	 *
	 * @since 3.0.0
	 * @param array<string,string> $args Field args.
	 * @return void
	 */
	public function renderHeadingOverlapThreshold( array $args ): void {
		$key   = $args['key'];
		$value = (int) self::get( $key );
		$name  = self::OPTION . '[' . esc_attr( $key ) . ']';

		printf(
			'<input type="number" name="%s" id="smart_image_matcher_%s" value="%s" min="0" max="100" step="1" />',
			esc_attr( $name ),
			esc_attr( $key ),
			esc_attr( (string) $value )
		);
		echo ' <span>%</span><p class="description">' . esc_html__( 'Sub-headings with keyword overlap above this % are skipped in Smart Hierarchy mode.', 'smart-image-matcher' ) . '</p>';
	}

	/**
	 * Render max matches per heading number input.
	 *
	 * @since 3.0.0
	 * @param array<string,string> $args Field args.
	 * @return void
	 */
	public function renderMaxMatches( array $args ): void {
		$key   = $args['key'];
		$value = (int) self::get( $key );
		$name  = self::OPTION . '[' . esc_attr( $key ) . ']';

		$max = 10;

		printf(
			'<input type="number" name="%s" id="smart_image_matcher_%s" value="%s" min="1" max="%s" step="1" />',
			esc_attr( $name ),
			esc_attr( $key ),
			esc_attr( (string) $value ),
			esc_attr( (string) $max )
		);
		echo '<p class="description">' . esc_html__( 'How many image choices to show for each heading in the editor modal.', 'smart-image-matcher' ) . '</p>';
	}

	/**
	 * Render minimum image spacing field.
	 *
	 * @since 3.0.0
	 * @param array<string,string> $args Field args.
	 * @return void
	 */
	public function renderMinimumImageSpacing( array $args ): void {
		$key   = $args['key'];
		$value = (int) self::get( $key );
		$name  = self::OPTION . '[' . esc_attr( $key ) . ']';

		printf(
			'<input type="number" name="%s" id="smart_image_matcher_%s" value="%s" min="0" max="5000" step="25" style="width:100px" /> px',
			esc_attr( $name ),
			esc_attr( $key ),
			esc_attr( (string) $value )
		);
		echo '<p class="description">' . esc_html__( 'Reserved for insertion spacing checks. Set to 0 to disable spacing restrictions.', 'smart-image-matcher' ) . '</p>';
	}

	/**
	 * Render match-result cache duration field.
	 *
	 * @since 3.0.0
	 * @param array<string,string> $args Field args.
	 * @return void
	 */
	public function renderCacheDuration( array $args ): void {
		$key   = $args['key'];
		$value = (int) self::get( $key );
		$name  = self::OPTION . '[' . esc_attr( $key ) . ']';

		printf(
			'<input type="number" name="%s" id="smart_image_matcher_%s" value="%s" min="0" max="86400" step="300" style="width:110px" /> seconds',
			esc_attr( $name ),
			esc_attr( $key ),
			esc_attr( (string) $value )
		);
		echo '<p class="description">' . esc_html__( 'Seconds to cache match results for unchanged posts. Use 0 to disable caching.', 'smart-image-matcher' ) . '</p>';
	}

	/**
	 * Render a generic checkbox.
	 *
	 * @since 3.0.0
	 * @param array<string,string> $args Field args.
	 * @return void
	 */
	public function renderCheckbox( array $args ): void {
		$key   = $args['key'];
		$value = (bool) self::get( $key );
		$name  = self::OPTION . '[' . esc_attr( $key ) . ']';

		printf(
			'<input type="checkbox" name="%s" id="smart_image_matcher_%s" value="1"%s />',
			esc_attr( $name ),
			esc_attr( $key ),
			checked( $value, true, false )
		);
	}

	/**
	 * Render whitelisted short words text input.
	 *
	 * @since 3.0.0
	 * @param array<string,string> $args Field args.
	 * @return void
	 */
	public function renderShortWords( array $args ): void {
		$key   = $args['key'];
		$value = (string) self::get( $key );
		$name  = self::OPTION . '[' . esc_attr( $key ) . ']';

		printf(
			'<input type="text" name="%s" id="smart_image_matcher_%s" value="%s" class="regular-text" />',
			esc_attr( $name ),
			esc_attr( $key ),
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'Comma-separated words that are ≤2 characters but must not be filtered out (e.g. "io").', 'smart-image-matcher' ) . '</p>';
	}

	/**
	 * Render a post-type comma-list text input.
	 *
	 * @since 3.0.0
	 * @param array<string,string> $args Field args.
	 * @return void
	 */
	public function renderPostTypeList( array $args ): void {
		$key   = $args['key'];
		$value = (string) self::get( $key );
		$name  = self::OPTION . '[' . esc_attr( $key ) . ']';

		printf(
			'<input type="text" name="%s" id="smart_image_matcher_%s" value="%s" class="regular-text" />',
			esc_attr( $name ),
			esc_attr( $key ),
			esc_attr( $value )
		);

		if ( 'fiaa_upload_post_types' === $key ) {
			echo '<p class="description">' . esc_html__( 'Post types checked when a new image is uploaded. Use post,page for normal sites.', 'smart-image-matcher' ) . '</p>';
			return;
		}

		if ( 'fiaa_cron_post_types' === $key ) {
			echo '<p class="description">' . esc_html__( 'Post types checked by the scheduled run. Use post unless you also want pages or custom post types scanned.', 'smart-image-matcher' ) . '</p>';
			return;
		}

		echo '<p class="description">' . esc_html__( 'Comma-separated post types, e.g. post,page', 'smart-image-matcher' ) . '</p>';
	}

	/**
	 * Render FIAA scheduled cron section description.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function renderFiaaCronSectionDescription(): void {
		echo '<p>' . esc_html__( 'Automatically checks for posts that need featured images. A daily run happens about 24 hours after the previous run, depending on WordPress cron and site traffic.', 'smart-image-matcher' ) . '</p>';
	}

	/**
	 * Render scheduled FIAA enabled toggle.
	 *
	 * @since 3.0.0
	 * @param array<string,string> $args Field args.
	 * @return void
	 */
	public function renderFiaaCronEnabled( array $args ): void {
		$this->renderCheckbox( $args );
		echo '<p class="description">' . esc_html__( 'Turn this on to let the site run featured-image matching automatically in the background.', 'smart-image-matcher' ) . '</p>';
	}

	/**
	 * Render scheduled FIAA interval select.
	 *
	 * @since 3.0.0
	 * @param array<string,string> $args Field args.
	 * @return void
	 */
	public function renderFiaaCronInterval( array $args ): void {
		$key      = $args['key'];
		$current  = (string) self::get( $key );
		$name     = self::OPTION . '[' . esc_attr( $key ) . ']';
		$options  = array(
			'hourly'     => __( 'Hourly', 'smart-image-matcher' ),
			'twicedaily' => __( 'Twice daily', 'smart-image-matcher' ),
			'daily'      => __( 'Daily', 'smart-image-matcher' ),
		);
		$disabled = '';

		printf(
			'<select name="%s" id="smart_image_matcher_%s"%s>',
			esc_attr( $name ),
			esc_attr( $key ),
			$disabled // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generated by WordPress disabled().
		);
		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Daily is recommended for most sites. Hourly can be useful during cleanup, but it creates more background work.', 'smart-image-matcher' ) . '</p>';
	}

	/**
	 * Render scheduled FIAA post types.
	 *
	 * @since 3.0.0
	 * @param array<string,string> $args Field args.
	 * @return void
	 */
	public function renderFiaaCronPostTypes( array $args ): void {
		$this->renderPostTypeList( $args );
	}

	/**
	 * Render scheduled FIAA post statuses.
	 *
	 * @since 3.0.0
	 * @param array<string,string> $args Field args.
	 * @return void
	 */
	public function renderFiaaCronPostStatuses( array $args ): void {
		$key   = $args['key'];
		$name  = self::OPTION . '[' . esc_attr( $key ) . '][]';
		$value = PostStatuses::sanitizeList( (string) self::get( $key ) );

		echo '<fieldset id="smart_image_matcher_' . esc_attr( $key ) . '" class="sim-checkbox-group">';
		foreach ( PostStatuses::queryable() as $status => $statusObject ) {
			printf(
				'<label><input type="checkbox" name="%1$s" value="%2$s"%3$s /> <span>%4$s</span></label>',
				esc_attr( $name ),
				esc_attr( $status ),
				checked( in_array( $status, $value, true ), true, false ),
				esc_html( $statusObject->label )
			);
		}
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'Comma-separated statuses. Use publish for live posts only. Add draft, pending, or future when you want to prepare unpublished posts too.', 'smart-image-matcher' ) . '</p>';
	}

	/**
	 * Render scheduled FIAA featured image filter.
	 *
	 * @since 3.0.0
	 * @param array<string,string> $args Field args.
	 * @return void
	 */
	public function renderFiaaCronFeaturedFilter( array $args ): void {
		$key     = $args['key'];
		$current = (string) self::get( $key );
		$name    = self::OPTION . '[' . esc_attr( $key ) . ']';
		$options = array(
			'missing' => __( 'Missing featured image', 'smart-image-matcher' ),
			'any'     => __( 'Any featured image state', 'smart-image-matcher' ),
			'has'     => __( 'Has featured image', 'smart-image-matcher' ),
		);

		echo '<select name="' . esc_attr( $name ) . '" id="smart_image_matcher_' . esc_attr( $key ) . '">';
		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Use Missing for normal daily runs. Use Any only when you want the scheduler to inspect every post in the selected statuses.', 'smart-image-matcher' ) . '</p>';
	}

	/**
	 * Render scheduled FIAA overwrite toggle.
	 *
	 * @since 3.0.0
	 * @param array<string,string> $args Field args.
	 * @return void
	 */
	public function renderFiaaCronOverwrite( array $args ): void {
		$this->renderCheckbox( $args );
		echo '<p class="description">' . esc_html__( 'Leave this off to protect existing featured images. Turn it on only when you want scheduled runs to replace them.', 'smart-image-matcher' ) . '</p>';
	}

	/**
	 * Render the AI features section description.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function renderAiSectionDescription(): void {
		if ( ! \SmartImageMatcher\AI\ProviderBridge::isAvailable() ) {
			$url = admin_url( 'options-general.php?page=connectors' );
			printf(
				'<p>%s <a href="%s">%s</a></p>',
				esc_html__( 'Configure an AI provider to enable these features.', 'smart-image-matcher' ),
				esc_url( $url ),
				esc_html__( 'Settings → Connectors', 'smart-image-matcher' )
			);
		}
	}

	/**
	 * Render the AI alt-text on upload toggle.
	 *
	 * @since 3.0.0
	 * @param array<string,string> $args Field args.
	 * @return void
	 */
	public function renderAiAltTextToggle( array $args ): void {
		$this->renderCheckbox( array( 'key' => 'ai_alt_text_on_upload' ) );
		echo '<p class="description">' . esc_html__( 'Generate alt text automatically when an image with no alt text is uploaded.', 'smart-image-matcher' ) . '</p>';
	}

	/**
	 * Render the vision matching toggle.
	 *
	 * @since 3.0.0
	 * @param array<string,string> $args Field args.
	 * @return void
	 */
	public function renderAiVisionToggle( array $args ): void {
		$this->renderCheckbox( array( 'key' => 'ai_vision_match_enabled' ) );
		echo '<p class="description">' . esc_html__( 'Blend visual content scoring (60%) with keyword scoring (40%) for higher accuracy. Uses additional AI credits per image scored.', 'smart-image-matcher' ) . '</p>';
	}

	/**
	 * Render the AI featured-image generation toggle.
	 *
	 * @since 3.0.0
	 * @param array<string,string> $args Field args.
	 * @return void
	 */
	public function renderAiFeaturedImageToggle( array $args ): void {
		$this->renderCheckbox( array( 'key' => 'ai_featured_image_enabled' ) );
		echo '<p class="description">' . esc_html__( 'When the Featured Image Auto-Assigner finds no slug match, generate a relevant image using AI. Only runs during the scheduled FIAA cron.', 'smart-image-matcher' ) . '</p>';
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Register a settings field with the correct option key baked into $args.
	 *
	 * @since 3.0.0
	 * @param string $section  Section ID.
	 * @param string $key      Setting key (used as the field ID).
	 * @param string $label    Field label.
	 * @param string $renderer Method name on $this to call for the HTML.
	 * @return void
	 */
	private function addField( string $section, string $key, string $label, string $renderer ): void {
		add_settings_field(
			'smart_image_matcher_' . $key,
			$label,
			array( $this, $renderer ),
			'smart_image_matcher_settings',
			$section,
			array( 'key' => $key, 'label_for' => 'smart_image_matcher_' . $key )
		);
	}
}
