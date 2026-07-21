<?php
/**
 * GitHub-backed WordPress update checker.
 *
 * Uses Yahnis Elsts Plugin Update Checker against the public GitHub repo
 * so sites installed from GitHub receive update notices like wp.org plugins.
 *
 * @package SmartImageMatcher
 * @since   3.0.8
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Update;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * Class GitHubUpdater
 *
 * @since 3.0.8
 */
class GitHubUpdater {

	/**
	 * Public GitHub repository that hosts releases.
	 *
	 * @var string
	 */
	const REPO_URL = 'https://github.com/Krafty-Sprouts-Media-LLC/Smart-Image-Matcher/';

	/**
	 * Plugin slug used by the update checker (must match the install folder).
	 *
	 * @var string
	 */
	const SLUG = 'smart-image-matcher';

	/**
	 * Register the update checker when enabled.
	 *
	 * @since 3.0.8
	 * @return void
	 */
	public function register(): void {
		if ( ! $this->isEnabled() ) {
			return;
		}

		if ( ! class_exists( PucFactory::class ) ) {
			return;
		}

		$checker = PucFactory::buildUpdateChecker(
			self::REPO_URL,
			SMART_IMAGE_MATCHER_PLUGIN_FILE,
			self::SLUG
		);

		// Prefer GitHub Releases on main; tags without a leading "v" are fine
		// (PUC strips an optional "v" prefix from tag names).
		$checker->setBranch( 'main' );

		// Use the release zip asset (correct plugin folder layout) instead of
		// the auto-generated source zipball.
		$api = $checker->getVcsApi();
		if ( $api && method_exists( $api, 'enableReleaseAssets' ) ) {
			$api->enableReleaseAssets( '/smart-image-matcher\\.zip($|[?&#])/i' );
		}
	}

	/**
	 * Whether GitHub updates should run.
	 *
	 * Disable for wp.org builds via:
	 * `define( 'SMART_IMAGE_MATCHER_DISABLE_GITHUB_UPDATES', true );`
	 * or the `smart_image_matcher_enable_github_updates` filter.
	 *
	 * @since 3.0.8
	 * @return bool
	 */
	private function isEnabled(): bool {
		if ( defined( 'SMART_IMAGE_MATCHER_DISABLE_GITHUB_UPDATES' ) && SMART_IMAGE_MATCHER_DISABLE_GITHUB_UPDATES ) {
			return false;
		}

		/**
		 * Filter whether the GitHub update checker is active.
		 *
		 * @since 3.0.8
		 *
		 * @param bool $enabled Default true for GitHub-distributed builds.
		 */
		return (bool) apply_filters( 'smart_image_matcher_enable_github_updates', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- smart_image_matcher_ is the project hook prefix.
	}
}
