<?php
/**
 * Database schema versioning and migration runner.
 *
 * Runs on plugins_loaded priority 9 so all services see a current schema.
 *
 * @package SmartImageMatcher
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Migrator
 *
 * @since 3.0.0
 */
class Migrator {

	/**
	 * Current schema version this build requires.
	 *
	 * Bump this constant whenever a new migration is added.
	 */
	const SCHEMA_VERSION = 3;

	/**
	 * Run any outstanding migrations.
	 *
	 * Safe to call on every request — only acts when smart_image_matcher_db_version is behind.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function maybeRun(): void {
		$installed = (int) get_option( 'smart_image_matcher_db_version', 0 );

		if ( $installed < 1 ) {
			$this->migration1CreateTables();
		}

		if ( $installed < 2 ) {
			$this->migration2AddHeadingHash();
		}

		if ( $installed < 3 ) {
			$this->migration3CreateInvertedIndex();
		}

		// Always ensure the inverted index table exists, even on sites that
		// were activated before Migration 3 was introduced.
		$this->ensureInvertedIndexExists();

		update_option( 'smart_image_matcher_db_version', self::SCHEMA_VERSION, false );
	}

	/**
	 * Run unconditionally on plugin activation.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function runOnActivation(): void {
		$this->migration1CreateTables();
		$this->migration2AddHeadingHash();
		$this->migration3CreateInvertedIndex();
		update_option( 'smart_image_matcher_db_version', self::SCHEMA_VERSION, false );
	}

	// -------------------------------------------------------------------------
	// Individual migrations
	// -------------------------------------------------------------------------

	/**
	 * Migration 1 — Create wp_smart_image_matcher_matches and wp_smart_image_matcher_queue tables.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	private function migration1CreateTables(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		// wp_smart_image_matcher_matches — per-heading match audit log + review-queue state.
		$matches_sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}smart_image_matcher_matches (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id      BIGINT UNSIGNED NOT NULL,
			image_id     BIGINT UNSIGNED NOT NULL,
			confidence_score INT NOT NULL DEFAULT 0,
			match_method VARCHAR(20) NOT NULL DEFAULT 'keyword',
			ai_reasoning TEXT,
			status       VARCHAR(20) NOT NULL DEFAULT 'pending',
			created_at   DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY post_id_idx (post_id),
			KEY status_idx (status)
		) {$charset};";

		// wp_smart_image_matcher_queue — bulk job metadata (one row per job, not per post-in-job).
		$queue_sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}smart_image_matcher_queue (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id       VARCHAR(64) NOT NULL,
			status       VARCHAR(20) NOT NULL DEFAULT 'queued',
			priority     INT NOT NULL DEFAULT 0,
			attempts     INT NOT NULL DEFAULT 0,
			error_message TEXT,
			started_at   DATETIME,
			finished_at  DATETIME,
			created_at   DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY job_id_idx (job_id),
			KEY status_idx (status)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $matches_sql );
		dbDelta( $queue_sql );
	}

	/**
	 * Migration 2 — Add heading_hash and heading_text columns to wp_smart_image_matcher_matches.
	 * Also expand wp_smart_image_matcher_queue schema for named bulk jobs.
	 *
	 * Drops pre-3.0.0 rows: heading_position was an unstable byte-offset key
	 * that cannot be meaningfully migrated.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	private function migration2AddHeadingHash(): void {
		global $wpdb;

		$matchesTable = esc_sql( $wpdb->prefix . 'smart_image_matcher_matches' );

		$hasHeadingHash = $this->columnExists( $matchesTable, 'heading_hash' );
		$hasHeadingText = $this->columnExists( $matchesTable, 'heading_text' );
		$hasHeadingTag  = $this->columnExists( $matchesTable, 'heading_tag' );

		if ( ! $hasHeadingHash || ! $hasHeadingText || ! $hasHeadingTag ) {
			$wpdb->query( "TRUNCATE TABLE {$matchesTable}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! $hasHeadingHash ) {
			$wpdb->query( "ALTER TABLE {$matchesTable} ADD COLUMN heading_hash VARCHAR(40) NOT NULL DEFAULT '' AFTER post_id" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! $hasHeadingText ) {
			$wpdb->query( "ALTER TABLE {$matchesTable} ADD COLUMN heading_text VARCHAR(255) NOT NULL DEFAULT '' AFTER heading_hash" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! $hasHeadingTag ) {
			$wpdb->query( "ALTER TABLE {$matchesTable} ADD COLUMN heading_tag VARCHAR(10) NOT NULL DEFAULT 'h2' AFTER heading_text" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! $this->indexExists( $matchesTable, 'heading_hash_idx' ) ) {
			$wpdb->query( "ALTER TABLE {$matchesTable} ADD KEY heading_hash_idx (heading_hash)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$queueTable = esc_sql( $wpdb->prefix . 'smart_image_matcher_queue' );

		if ( ! $this->columnExists( $queueTable, 'job_id' ) ) {
			$wpdb->query( "ALTER TABLE {$queueTable} ADD COLUMN job_id VARCHAR(64) NOT NULL DEFAULT '' AFTER id" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! $this->columnExists( $queueTable, 'totals' ) ) {
			$wpdb->query( "ALTER TABLE {$queueTable} ADD COLUMN totals TEXT AFTER error_message" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! $this->columnExists( $queueTable, 'started_at' ) ) {
			$wpdb->query( "ALTER TABLE {$queueTable} ADD COLUMN started_at DATETIME AFTER totals" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! $this->columnExists( $queueTable, 'finished_at' ) ) {
			$wpdb->query( "ALTER TABLE {$queueTable} ADD COLUMN finished_at DATETIME AFTER started_at" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! $this->indexExists( $queueTable, 'job_id_idx' ) ) {
			$wpdb->query( "ALTER TABLE {$queueTable} ADD KEY job_id_idx (job_id)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Check whether a table column exists.
	 *
	 * @since 3.0.0
	 * @param string $table  Full table name.
	 * @param string $column Column name.
	 * @return bool
	 */
	private function columnExists( string $table, string $column ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				DB_NAME,
				$table,
				$column
			)
		);
	}

	/**
	 * Check whether a table index exists.
	 *
	 * @since 3.0.0
	 * @param string $table Full table name.
	 * @param string $index Index name.
	 * @return bool
	 */
	private function indexExists( string $table, string $index ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s',
				DB_NAME,
				$table,
				$index
			)
		);
	}

	/**
	 * Migration 3 — Create wp_smart_image_matcher_image_terms inverted index.
	 *
	 * Also runs if the table is missing regardless of schema version,
	 * so sites that activated before this migration was added get it too.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	private function migration3CreateInvertedIndex(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}smart_image_matcher_image_terms (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			image_id    BIGINT UNSIGNED NOT NULL,
			term        VARCHAR(100)    NOT NULL,
			weight      TINYINT UNSIGNED NOT NULL DEFAULT 10,
			source      VARCHAR(20)     NOT NULL DEFAULT 'filename',
			PRIMARY KEY (id),
			KEY term_idx (term),
			KEY image_id_idx (image_id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Ensure the inverted index table exists even if the migration version
	 * was already set before Migration 3 was introduced.
	 *
	 * Called from maybeRun() unconditionally.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	private function ensureInvertedIndexExists(): void {
		global $wpdb;

		// Quick existence check — no query if table already exists.
		$table  = $wpdb->prefix . 'smart_image_matcher_image_terms';
		$exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
				DB_NAME,
				$table
			)
		);

		if ( ! $exists ) {
			$this->migration3CreateInvertedIndex();
		}
	}

	// -------------------------------------------------------------------------
	// Cleanup (called by uninstall.php)
	// -------------------------------------------------------------------------

	/**
	 * Drop all plugin tables.
	 *
	 * Only called from uninstall.php when the user opted in.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public static function dropTables(): void {
		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'smart_image_matcher_matches',
			$wpdb->prefix . 'smart_image_matcher_queue',
			$wpdb->prefix . 'smart_image_matcher_image_terms',
		);

		foreach ( $tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}
}
