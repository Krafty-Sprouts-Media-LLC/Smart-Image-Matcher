<?php
/**
 * Everything the Dashboard shows, gathered in one place.
 *
 * Times: queue rows store site-local MySQL datetimes, so this class works in
 * "local timestamps" (current_time( 'timestamp' ) and strtotime() of those
 * strings). Tick and Action Scheduler times are real UTC and are shifted.
 *
 * @package SmartImageMatcher\Admin
 * @since   3.7.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\Domain\RelevanceGuard;
use SmartImageMatcher\Logging\Logger;
use SmartImageMatcher\Queue\Queue;
use SmartImageMatcher\Queue\QueueWatchdog;
use SmartImageMatcher\REST\BulkController;
use SmartImageMatcher\Settings\Settings;

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PSR-4 camelCase methods (see agents.md).
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin tables and Action Scheduler tables.

/**
 * Class DashboardData
 *
 * @since 3.7.0
 */
class DashboardData {

	/** Runtime key holding dismissed "Needs attention" items. */
	const DISMISSED_KEY = 'dashboard_dismissed';

	/** Hours shown by the runs chart. */
	const CHART_HOURS = 48;

	/** Errors older than this many days are shown as old. */
	const OLD_ERROR_DAYS = 3;

	/**
	 * Local "now" (site timezone, as a Unix-style number).
	 *
	 * @var int
	 */
	private int $now;

	/**
	 * Seconds to add to a real UTC timestamp to get a local one.
	 *
	 * @var int
	 */
	private int $offset;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->now    = (int) current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- compared with local-time DB columns.
		$this->offset = $this->now - time();
	}

	/**
	 * Everything for the page.
	 *
	 * @since 3.7.0
	 * @return array<string, mixed>
	 */
	public function collect(): array {
		$health = QueueWatchdog::health();
		$jobs   = $this->recentJobs();
		$open   = $this->openJob( $jobs );

		return array(
			'health'    => $health,
			'kpis'      => array(
				'today'    => $this->insertedOn( $jobs, 0 ),
				'yesterday' => $this->insertedOn( $jobs, 1 ),
				'review'   => $this->reviewBacklog(),
				'run'      => $open,
				'coverage' => $this->coverage(),
			),
			'slots'     => $this->slots( $jobs, $health['ticks'] ),
			'runs'      => array_slice( $this->runRows( $jobs ), 0, 8 ),
			'queue'     => $this->queueCard( $health ),
			'attention' => $this->attention( $jobs ),
			'errors'    => self::groupErrors( Logger::getRecentErrors(), $this->now ),
		);
	}

	/**
	 * Format a local timestamp.
	 *
	 * @param int    $local  Local timestamp.
	 * @param string $format Date format.
	 * @return string
	 */
	public static function fmt( int $local, string $format = 'j M H:i' ): string {
		return $local > 0 ? gmdate( $format, $local ) : '—';
	}

	/**
	 * Short "Today 09:14" / "Yesterday 23:02" / "22 Sep 18:03".
	 *
	 * @param int $local Local timestamp.
	 * @return string
	 */
	public function when( int $local ): string {
		if ( $local <= 0 ) {
			return '—';
		}
		$day = gmdate( 'Y-m-d', $local );
		if ( gmdate( 'Y-m-d', $this->now ) === $day ) {
			/* translators: %s: time */
			return sprintf( __( 'Today %s', 'smart-image-matcher' ), gmdate( 'H:i', $local ) );
		}
		if ( gmdate( 'Y-m-d', $this->now - DAY_IN_SECONDS ) === $day ) {
			/* translators: %s: time */
			return sprintf( __( 'Yesterday %s', 'smart-image-matcher' ), gmdate( 'H:i', $local ) );
		}
		return gmdate( 'j M H:i', $local );
	}

	/**
	 * Local timestamp of the next scheduled tick, from a real UTC one.
	 *
	 * @param int $utc Real UTC timestamp.
	 * @return int
	 */
	public function toLocal( int $utc ): int {
		return $utc > 0 ? $utc + $this->offset : 0;
	}

	// -------------------------------------------------------------------------
	// Jobs
	// -------------------------------------------------------------------------

	/**
	 * Article runs from the last two days (and at least the start of yesterday), hydrated.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function recentJobs(): array {
		global $wpdb;

		$since = min( $this->now - self::CHART_HOURS * HOUR_IN_SECONDS, (int) strtotime( gmdate( 'Y-m-d 00:00:00', $this->now - DAY_IN_SECONDS ) ) );
		$table = $wpdb->prefix . 'smart_image_matcher_queue';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE created_at >= %s OR status IN ('queued','processing') ORDER BY created_at DESC LIMIT 400",
				gmdate( 'Y-m-d H:i:s', $since )
			),
			ARRAY_A
		);

		// Keep showing the last few runs even when nothing ran in two days.
		if ( ! is_array( $rows ) || count( $rows ) < 8 ) {
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 8", ARRAY_A );
		}

		$bulk = new BulkController();
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$job  = $bulk->hydrateJobRow( $row );
			$mode = (string) ( $job['config']['mode'] ?? '' );
			if ( 'insert-approved' === $mode || false !== strpos( $mode, 'audit' ) ) {
				continue;
			}
			$totals         = json_decode( (string) ( $row['totals'] ?? '' ), true );
			$job['errors']  = is_array( $totals ) ? (int) ( $totals['errors'] ?? 0 ) : 0;
			$job['at']      = (int) strtotime( (string) $row['created_at'] );
			$job['start']   = (int) strtotime( (string) ( $row['started_at'] ?: $row['created_at'] ) );
			$job['trigger'] = false !== strpos( (string) $row['job_id'], '_fiaa_scheduled_' )
				? __( 'Scheduled', 'smart-image-matcher' )
				: ( 'generate-featured' === $mode ? __( 'Generate featured', 'smart-image-matcher' ) : __( 'Bulk Processor', 'smart-image-matcher' ) );
			$out[]          = $job;
		}
		return $out;
	}

	/**
	 * Headings inserted (and sent to Review) by runs started N days ago.
	 *
	 * @param array<int, array<string, mixed>> $jobs     Jobs.
	 * @param int                              $days_ago 0 = today.
	 * @return array{inserted:int, review:int}
	 */
	private function insertedOn( array $jobs, int $days_ago ): array {
		$day = gmdate( 'Y-m-d', $this->now - $days_ago * DAY_IN_SECONDS );
		$out = array( 'inserted' => 0, 'review' => 0 );
		foreach ( $jobs as $job ) {
			if ( gmdate( 'Y-m-d', (int) $job['at'] ) === $day ) {
				$out['inserted'] += (int) $job['inserted'];
				$out['review']   += (int) $job['review'];
			}
		}
		return $out;
	}

	/**
	 * The newest run still queued or processing, with pace and ETA.
	 *
	 * @param array<int, array<string, mixed>> $jobs Jobs.
	 * @return array<string, mixed>|null
	 */
	private function openJob( array $jobs ): ?array {
		foreach ( $jobs as $job ) {
			if ( ! in_array( (string) $job['status'], array( 'queued', 'processing' ), true ) ) {
				continue;
			}
			$done    = (int) $job['done'];
			$total   = (int) $job['total'];
			$elapsed = max( 1, (int) floor( ( $this->now - (int) $job['start'] ) / 60 ) );
			$eta     = $done > 0 ? (int) round( ( $total - $done ) * ( $elapsed / $done ) ) : null;
			return array(
				'job_id'  => (string) $job['job_id'],
				'trigger' => (string) $job['trigger'],
				'done'    => $done,
				'total'   => $total,
				'started' => $this->when( (int) $job['start'] ),
				'eta_min' => $eta,
			);
		}
		return null;
	}

	/**
	 * Recent runs table rows.
	 *
	 * @param array<int, array<string, mixed>> $jobs Jobs.
	 * @return array<int, array<string, mixed>>
	 */
	private function runRows( array $jobs ): array {
		$rows = array();
		foreach ( $jobs as $job ) {
			$status = (string) $job['status'];
			$done   = (int) $job['done'];
			$total  = (int) $job['total'];
			$note   = '';
			if ( 'failed' === $status ) {
				$note = (string) ( $job['error_message'] ?? '' );
			} elseif ( (int) $job['errors'] > 0 ) {
				/* translators: %d: count */
				$note = sprintf( _n( '%d article failed', '%d articles failed', (int) $job['errors'], 'smart-image-matcher' ), (int) $job['errors'] );
			}

			$rows[] = array(
				'when'     => $this->when( (int) $job['at'] ),
				'trigger'  => (string) $job['trigger'],
				'status'   => $status,
				'articles' => in_array( $status, array( 'queued', 'processing', 'failed' ), true ) || $done !== $total
					? sprintf( '%s / %s', number_format_i18n( $done ), number_format_i18n( $total ) )
					: number_format_i18n( $total ),
				'inserted' => (int) $job['inserted'],
				'review'   => (int) $job['review'],
				'skipped'  => (int) $job['skipped'] + (int) $job['has_image'],
				'note'     => $note,
			);
		}
		return $rows;
	}

	// -------------------------------------------------------------------------
	// Chart
	// -------------------------------------------------------------------------

	/**
	 * One slot per hour: what ran in it (or why nothing did).
	 *
	 * @param array<int, array<string, mixed>> $jobs  Jobs.
	 * @param array<int, mixed>                $ticks Watchdog ticks (newest first, real UTC).
	 * @return array<int, array<string, mixed>>
	 */
	private function slots( array $jobs, array $ticks ): array {
		$hour  = (int) ( floor( $this->now / HOUR_IN_SECONDS ) * HOUR_IN_SECONDS );
		$slots = array();
		for ( $i = self::CHART_HOURS - 1; $i >= 0; $i-- ) {
			$t                             = $hour - $i * HOUR_IN_SECONDS;
			$slots[ gmdate( 'Y-m-d H', $t ) ] = array(
				'label' => gmdate( 'j M H:00', $t ),
				'hour'  => (int) gmdate( 'G', $t ),
				'day'   => gmdate( 'j M', $t ),
				'state' => 'none',
				'ins'   => 0,
				'rev'   => 0,
				'note'  => '',
			);
		}

		foreach ( array_reverse( $ticks ) as $tick ) {
			if ( ! is_array( $tick ) ) {
				continue;
			}
			$key = gmdate( 'Y-m-d H', $this->toLocal( (int) ( $tick['at'] ?? 0 ) ) );
			if ( ! isset( $slots[ $key ] ) ) {
				continue;
			}
			$outcome = (string) ( $tick['outcome'] ?? '' );
			if ( 'skipped' === $outcome ) {
				$slots[ $key ]['state'] = 'skip';
			}
			$slots[ $key ]['note'] = (string) ( $tick['detail'] ?? '' );
		}

		foreach ( array_reverse( $jobs ) as $job ) {
			$key = gmdate( 'Y-m-d H', (int) $job['at'] );
			if ( ! isset( $slots[ $key ] ) ) {
				continue;
			}
			$running                 = in_array( (string) $job['status'], array( 'queued', 'processing' ), true );
			$slots[ $key ]['state']  = $running ? 'live' : 'ran';
			$slots[ $key ]['ins']   += (int) $job['inserted'];
			$slots[ $key ]['rev']   += (int) $job['review'];
			$slots[ $key ]['note']   = sprintf(
				/* translators: 1: trigger, 2: done, 3: total */
				__( '%1$s · %2$d / %3$d articles', 'smart-image-matcher' ),
				(string) $job['trigger'],
				(int) $job['done'],
				(int) $job['total']
			);
		}

		return array_values( $slots );
	}

	// -------------------------------------------------------------------------
	// KPIs
	// -------------------------------------------------------------------------

	/**
	 * Pending Review: articles, suggestions, one-word matches, and what ≥ auto would clear.
	 *
	 * @return array<string, int>
	 */
	private function reviewBacklog(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'smart_image_matcher_matches';
		$auto  = (int) Settings::get( 'auto_insert_threshold' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) AS articles, COUNT(*) AS suggestions, SUM(confidence_score >= %d) AS strong FROM {$table} WHERE status = 'pending'",
				$auto
			),
			ARRAY_A
		);

		return array(
			'articles'    => (int) ( $row['articles'] ?? 0 ),
			'suggestions' => (int) ( $row['suggestions'] ?? 0 ),
			'strong'      => (int) ( $row['strong'] ?? 0 ),
			'thin'        => $this->thinCount(),
		);
	}

	/**
	 * Pending heading suggestions that share at most one topic word with their image.
	 *
	 * @return int
	 */
	private function thinCount(): int {
		$cached = get_transient( 'smart_image_matcher_dash_thin' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'smart_image_matcher_matches';
		$rows  = $wpdb->get_results(
			"SELECT m.heading_text, pm.meta_value AS file
			 FROM {$table} m
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = m.image_id AND pm.meta_key = '_wp_attached_file'
			 WHERE m.status = 'pending' AND m.heading_hash <> 'featured'
			 LIMIT 3000",
			ARRAY_A
		);

		$thin = 0;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$shared = RelevanceGuard::sharedTopicTerms( (string) $row['heading_text'], array( 'filename' => wp_basename( (string) $row['file'] ) ) );
			if ( count( $shared ) <= 1 ) {
				++$thin;
			}
		}

		set_transient( 'smart_image_matcher_dash_thin', $thin, 10 * MINUTE_IN_SECONDS );
		return $thin;
	}

	/**
	 * Featured-image coverage for posts the plugin works on (each post counted once).
	 *
	 * @return array<string, int>
	 */
	private function coverage(): array {
		global $wpdb;

		$statuses = "'publish','draft','pending','future','private'";
		$total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status IN ({$statuses})" );
		$with     = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_thumbnail_id' AND pm.meta_value > 0
			 WHERE p.post_type = 'post' AND p.post_status IN ({$statuses})"
		);
		$with = min( $with, $total );

		return array(
			'total'   => $total,
			'with'    => $with,
			'missing' => $total - $with,
			'pct'     => $total > 0 ? (int) floor( ( $with / $total ) * 100 ) : 0,
		);
	}

	// -------------------------------------------------------------------------
	// Queue card
	// -------------------------------------------------------------------------

	/**
	 * Queue numbers, what is running now, pace and the last finished article.
	 *
	 * @param array<string, mixed> $health QueueWatchdog::health().
	 * @return array<string, mixed>
	 */
	private function queueCard( array $health ): array {
		global $wpdb;

		$out = array(
			'pending'  => (int) $health['queue']['pending'],
			'past_due' => (int) $health['queue']['past_due'],
			'running'  => (int) $health['queue']['in_progress'],
			'now'      => null,
			'pace'     => 0.0,
			'last'     => null,
			'watchdog' => $health['watchdog'],
		);

		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$groups  = $wpdb->prefix . 'actionscheduler_groups';
		if ( $actions !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $actions ) ) ) {
			return $out;
		}

		$running = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT a.hook, a.args, a.extended_args, a.last_attempt_gmt
				 FROM {$actions} a INNER JOIN {$groups} g ON g.group_id = a.group_id
				 WHERE g.slug = %s AND a.status = 'in-progress'
				 ORDER BY a.last_attempt_gmt ASC LIMIT 1",
				Queue::GROUP
			),
			ARRAY_A
		);
		if ( is_array( $running ) ) {
			$post_id    = self::postIdFromArgs( $running );
			$out['now'] = array(
				'post_id' => $post_id,
				'title'   => $post_id ? get_the_title( $post_id ) : (string) $running['hook'],
				'seconds' => max( 0, time() - (int) strtotime( (string) $running['last_attempt_gmt'] . ' UTC' ) ),
			);
		}

		$done_30 = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$actions} a INNER JOIN {$groups} g ON g.group_id = a.group_id
				 WHERE g.slug = %s AND a.hook = %s AND a.status = 'complete' AND a.last_attempt_gmt > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE)",
				Queue::GROUP,
				Queue::HOOK_PROCESS_ARTICLE
			)
		);
		$out['pace'] = round( $done_30 / 30, 1 );

		$last = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT a.args, a.extended_args, a.last_attempt_gmt
				 FROM {$actions} a INNER JOIN {$groups} g ON g.group_id = a.group_id
				 WHERE g.slug = %s AND a.hook = %s AND a.status = 'complete'
				 ORDER BY a.last_attempt_gmt DESC LIMIT 1",
				Queue::GROUP,
				Queue::HOOK_PROCESS_ARTICLE
			),
			ARRAY_A
		);
		if ( is_array( $last ) ) {
			$post_id     = self::postIdFromArgs( $last );
			$out['last'] = array(
				'post_id' => $post_id,
				'title'   => $post_id ? get_the_title( $post_id ) : '',
				'when'    => $this->when( $this->toLocal( (int) strtotime( (string) $last['last_attempt_gmt'] . ' UTC' ) ) ),
			);
		}

		return $out;
	}

	/**
	 * Post ID stored in an Action Scheduler row's args.
	 *
	 * @param array<string, mixed> $row Action row.
	 * @return int
	 */
	private static function postIdFromArgs( array $row ): int {
		$raw  = ! empty( $row['extended_args'] ) ? (string) $row['extended_args'] : (string) ( $row['args'] ?? '' );
		$args = json_decode( $raw, true );
		if ( ! is_array( $args ) ) {
			return 0;
		}
		if ( isset( $args['post_id'] ) ) {
			return (int) $args['post_id'];
		}
		$first = reset( $args );
		return is_array( $first ) && isset( $first['post_id'] ) ? (int) $first['post_id'] : 0;
	}

	// -------------------------------------------------------------------------
	// Needs attention
	// -------------------------------------------------------------------------

	/**
	 * Things that need a person: lost articles, one-word matches, a large backlog.
	 *
	 * @param array<int, array<string, mixed>> $jobs Jobs.
	 * @return array<int, array<string, mixed>>
	 */
	private function attention( array $jobs ): array {
		$dismissed = self::dismissed();
		$items     = array();

		foreach ( $jobs as $job ) {
			$lost = (int) $job['total'] - (int) $job['done'];
			$key  = 'lost:' . (string) $job['job_id'];
			if ( 'failed' !== (string) $job['status'] || $lost <= 0 || in_array( $key, $dismissed, true ) ) {
				continue;
			}
			$items[] = array(
				'key'      => $key,
				'severity' => 'bad',
				/* translators: 1: count, 2: when */
				'title'    => sprintf( _n( '%1$d article never finished in the %2$s run', '%1$d articles never finished in the %2$s run', $lost, 'smart-image-matcher' ), $lost, $this->when( (int) $job['at'] ) ),
				'text'     => (string) ( $job['error_message'] ?? '' ),
				'job_id'   => (string) $job['job_id'],
			);
		}

		$review = $this->reviewBacklog();
		if ( $review['thin'] > 0 && ! in_array( 'thin', $dismissed, true ) ) {
			$items[] = array(
				'key'      => 'thin',
				'severity' => 'warn',
				/* translators: %d: count */
				'title'    => sprintf( _n( '%d Review suggestion matched on a single word', '%d Review suggestions matched on a single word', $review['thin'], 'smart-image-matcher' ), $review['thin'] ),
				'text'     => __( 'These are the ones most often wrong. Each shows the shared word in Review.', 'smart-image-matcher' ),
				'link'     => admin_url( 'admin.php?page=smart-image-matcher-bulk&sim_tab=review' ),
				'link_text' => __( 'Review these', 'smart-image-matcher' ),
			);
		}

		if ( $review['articles'] >= 50 && ! in_array( 'backlog', $dismissed, true ) ) {
			$items[] = array(
				'key'      => 'backlog',
				'severity' => 'info',
				/* translators: %s: count */
				'title'    => sprintf( __( '%s articles are waiting in Review', 'smart-image-matcher' ), number_format_i18n( $review['articles'] ) ),
				'text'     => sprintf(
					/* translators: 1: threshold, 2: count */
					__( 'Approving everything at %1$d%% and up would clear %2$s suggestions.', 'smart-image-matcher' ),
					(int) Settings::get( 'auto_insert_threshold' ),
					number_format_i18n( $review['strong'] )
				),
				'link'     => admin_url( 'admin.php?page=smart-image-matcher-bulk&sim_tab=review' ),
				'link_text' => __( 'Open Review', 'smart-image-matcher' ),
			);
		}

		return $items;
	}

	/**
	 * Dismissed attention keys.
	 *
	 * @return string[]
	 */
	public static function dismissed(): array {
		$runtime = get_option( Settings::RUNTIME_OPTION, array() );
		$list    = is_array( $runtime ) && isset( $runtime[ self::DISMISSED_KEY ] ) && is_array( $runtime[ self::DISMISSED_KEY ] ) ? $runtime[ self::DISMISSED_KEY ] : array();
		return array_map( 'strval', $list );
	}

	/**
	 * Dismiss one attention item (newest 100 kept).
	 *
	 * @param string $key Item key.
	 * @return void
	 */
	public static function dismiss( string $key ): void {
		$runtime = get_option( Settings::RUNTIME_OPTION, array() );
		$runtime = is_array( $runtime ) ? $runtime : array();
		$list    = self::dismissed();
		array_unshift( $list, $key );
		$runtime[ self::DISMISSED_KEY ] = array_slice( array_values( array_unique( $list ) ), 0, 100 );
		update_option( Settings::RUNTIME_OPTION, $runtime, false );
	}

	// -------------------------------------------------------------------------
	// Errors
	// -------------------------------------------------------------------------

	/**
	 * Group stored errors by message (newest group first).
	 *
	 * @since 3.7.0
	 * @param array<int, array{at:string,message:string,context:array<string,string>}> $errors Newest first.
	 * @param int                                                                         $now    Local timestamp.
	 * @return array<int, array<string, mixed>>
	 */
	public static function groupErrors( array $errors, int $now ): array {
		$groups = array();
		foreach ( $errors as $error ) {
			$message = (string) $error['message'];
			$at      = (int) strtotime( (string) $error['at'] );
			if ( ! isset( $groups[ $message ] ) ) {
				$groups[ $message ] = array(
					'message' => $message,
					'count'   => 0,
					'first'   => $at,
					'last'    => $at,
					'posts'   => array(),
					'details' => array(),
				);
			}
			$group           = $groups[ $message ];
			$group['count'] += 1;
			$group['first']  = min( $group['first'], $at );
			$group['last']   = max( $group['last'], $at );

			$context = (array) $error['context'];
			if ( ! empty( $context['post_id'] ) ) {
				$group['posts'][ (int) $context['post_id'] ] = true;
			}
			$pairs = array();
			foreach ( $context as $key => $value ) {
				$pairs[] = $key . '=' . $value;
			}
			$line    = implode( ' ', $pairs );
			$details = (array) $group['details'];
			if ( '' !== $line && count( $details ) < 10 && ! in_array( $line, $details, true ) ) {
				$details[] = $line;
			}
			$group['details']   = $details;
			$groups[ $message ] = $group;
		}

		$out = array();
		foreach ( $groups as $group ) {
			$group['posts'] = array_keys( $group['posts'] );
			$group['old']   = ( $now - $group['last'] ) > self::OLD_ERROR_DAYS * DAY_IN_SECONDS;
			$out[]          = $group;
		}
		return $out;
	}
}
