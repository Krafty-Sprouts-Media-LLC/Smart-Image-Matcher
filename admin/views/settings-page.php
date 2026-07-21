<?php
/**
 * Settings page view.
 *
 * Rendered by Settings::renderSettingsPage().
 * Uses the WordPress Settings API, but presents registered sections in a
 * WordPress-native card layout with visible anchored sections.
 *
 * @package SmartImageMatcher
 * @since   3.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template-local variables are scoped to this admin view include.

global $wp_settings_sections, $wp_settings_fields;

$smart_image_matcher_settings_sections = array(
	'smart_image_matcher_matching'    => __( 'Matching', 'smart-image-matcher' ),
	'smart_image_matcher_performance' => __( 'Performance', 'smart-image-matcher' ),
	'smart_image_matcher_linguistics' => __( 'Linguistics', 'smart-image-matcher' ),
	'smart_image_matcher_fiaa_free'   => __( 'Featured Images', 'smart-image-matcher' ),
	'smart_image_matcher_fiaa_cron'   => __( 'Automation', 'smart-image-matcher' ),
	'smart_image_matcher_ai'          => __( 'AI Features', 'smart-image-matcher' ),
	'smart_image_matcher_developer'   => __( 'Developer', 'smart-image-matcher' ),
);

$smart_image_matcher_render_settings_section = static function ( string $section_id ) use ( $wp_settings_sections, $wp_settings_fields ): void {
	$section = $wp_settings_sections['smart_image_matcher_settings'][ $section_id ] ?? null;

	if ( ! is_array( $section ) ) {
		return;
	}

	$title  = isset( $section['title'] ) ? (string) $section['title'] : '';
	$fields = $wp_settings_fields['smart_image_matcher_settings'][ $section_id ] ?? array();
	?>
	<section class="sim-card sim-settings-section" id="<?php echo esc_attr( $section_id ); ?>">
		<div class="sim-section-head">
			<h2><?php echo esc_html( $title ); ?></h2>
			<?php
			if ( isset( $section['callback'] ) && is_callable( $section['callback'] ) ) {
				call_user_func( $section['callback'], $section );
			}
			?>
		</div>

		<?php if ( ! empty( $fields ) ) : ?>
			<table class="form-table sim-form-table" role="presentation">
				<tbody>
					<?php foreach ( $fields as $field ) : ?>
						<?php $field_args = isset( $field['args'] ) && is_array( $field['args'] ) ? $field['args'] : array(); ?>
						<tr>
							<th scope="row">
								<?php
								if ( ! empty( $field_args['label_for'] ) ) {
									printf(
										'<label for="%1$s">%2$s</label>',
										esc_attr( (string) $field_args['label_for'] ),
										esc_html( (string) $field['title'] )
									);
								} else {
									echo esc_html( (string) $field['title'] );
								}
								?>
							</th>
							<td>
								<?php
								if ( isset( $field['callback'] ) && is_callable( $field['callback'] ) ) {
									call_user_func( $field['callback'], $field_args );
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>
	<?php
};
?>
<div class="wrap sim-admin-page sim-settings-page">
	<div class="sim-page-head">
		<div>
			<h1><?php esc_html_e( 'Smart Image Matcher Settings', 'smart-image-matcher' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Configure matching, featured-image assignment, automation boundaries, and diagnostics.', 'smart-image-matcher' ); ?>
			</p>
		</div>
	</div>

	<?php settings_errors( 'smart_image_matcher_settings' ); ?>

	<form method="post" action="options.php">
		<?php settings_fields( 'smart_image_matcher_settings_group' ); ?>

		<div class="sim-settings-layout">
			<nav class="sim-settings-nav" aria-label="<?php esc_attr_e( 'Settings sections', 'smart-image-matcher' ); ?>">
				<?php foreach ( $smart_image_matcher_settings_sections as $section_id => $label ) : ?>
					<?php if ( isset( $wp_settings_sections['smart_image_matcher_settings'][ $section_id ] ) ) : ?>
						<a href="#<?php echo esc_attr( $section_id ); ?>"><?php echo esc_html( $label ); ?></a>
					<?php endif; ?>
				<?php endforeach; ?>
			</nav>

			<div class="sim-settings-sections">
				<?php
				foreach ( array_keys( $smart_image_matcher_settings_sections ) as $section_id ) {
					$smart_image_matcher_render_settings_section( $section_id );
				}
				?>

				<div class="sim-settings-actions">
					<?php submit_button( __( 'Save Settings', 'smart-image-matcher' ), 'primary', 'submit', false ); ?>
				</div>
			</div>
		</div>
	</form>
</div>
<?php // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound ?>
