<?php
/**
 * Renders the "Premium" badge and disabled state for locked settings.
 *
 * @package SmartImageMatcher\UI
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\UI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\Premium;

/**
 * Class PremiumLock
 *
 * @since 3.0.0
 */
class PremiumLock {

	/**
	 * Render a disabled input with a "Premium" badge when the feature is not active.
	 *
	 * Usage in a view file:
	 *   PremiumLock::wrap( 'bulk_processor', function() { echo $my_field_html; } );
	 *
	 * @since 3.0.0
	 * @param string   $featureSlug Feature slug passed to Premium::has().
	 * @param callable $render      Callable that echoes the field markup.
	 * @return void
	 */
	public static function wrap( string $featureSlug, callable $render ): void {
		if ( Premium::has( $featureSlug ) ) {
			$render();
			return;
		}
		?>
		<div class="sim-premium-lock" aria-label="<?php esc_attr_e( 'Premium feature', 'smart-image-matcher' ); ?>">
			<span class="sim-premium-badge"><?php esc_html_e( 'Premium', 'smart-image-matcher' ); ?></span>
			<div class="sim-premium-locked-content" aria-disabled="true">
				<?php $render(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Echo a "Requires Pro" upgrade link.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public static function upgradeLink(): void {
		?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=smart-image-matcher-upgrade' ) ); ?>"
		   class="sim-upgrade-link">
			<?php esc_html_e( 'Upgrade to Smart Image Matcher Pro →', 'smart-image-matcher' ); ?>
		</a>
		<?php
	}
}
