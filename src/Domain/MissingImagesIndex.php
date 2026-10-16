<?php
/**
 * Per-post index of headings that have no image under them.
 *
 * Feeds the Missing Images page. Each post stores its list in post meta so
 * the page reads and filters with plain meta queries instead of parsing
 * thousands of posts per request. Kept fresh by save_post (which also fires
 * after every SIM insert) and built for existing posts by a batched
 * Action Scheduler scan.
 *
 * @package SmartImageMatcher\Domain
 * @since   3.6.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\Insertion\InsertionService;
use SmartImageMatcher\Settings\Settings;

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PSR-4 camelCase methods (see agents.md).

/**
 * Class MissingImagesIndex
 *
 * @since 3.6.0
 */
class MissingImagesIndex {

	/** Full entry: total, all_missing, heads[]. */
	const META = '_smart_image_matcher_missing';

	/** Headings without an image (queryable number). */
	const META_COUNT = '_smart_image_matcher_missing_count';

	/** Numbered headings without an image (queryable number). */
	const META_NUMBERED = '_smart_image_matcher_missing_numbered';

	/** "1" when no heading in the article has an image. */
	const META_ALL = '_smart_image_matcher_missing_all';

	/** Heading hashes the user marked "not needed". */
	const META_IGNORED = '_smart_image_matcher_missing_ignored';

	/** Option holding the scan cursor { after_id, done, indexed }. Absent = nothing to do. */
	const SCAN_STATE_OPTION = 'smart_image_matcher_missing_scan_state';

	/** Post types indexed. */
	const POST_TYPES = array( 'post', 'page' );

	/** Post statuses indexed. */
	const STATUSES = array( 'publish', 'draft', 'future', 'pending', 'private' );

	/**
	 * Heading extractor.
	 *
	 * @var HeadingExtractor
	 */
	private HeadingExtractor $extractor;

	/**
	 * Insertion service (decides whether a heading already has an image).
	 *
	 * @var InsertionService
	 */
	private InsertionService $insertion;

	/**
	 * Matcher (hierarchy filter, so the list matches what article runs try to fill).
	 *
	 * @var Matcher
	 */
	private Matcher $matcher;

	/**
	 * Constructor.
	 *
	 * @since 3.6.0
	 * @param HeadingExtractor $extractor Heading extractor.
	 * @param InsertionService $insertion Insertion service.
	 * @param Matcher          $matcher   Matcher.
	 */
	public function __construct( HeadingExtractor $extractor, InsertionService $insertion, Matcher $matcher ) {
		$this->extractor = $extractor;
		$this->insertion = $insertion;
		$this->matcher   = $matcher;
	}

	// -------------------------------------------------------------------------
	// Pure helpers
	// -------------------------------------------------------------------------

	/**
	 * Whether a heading is a list item: "1. Red Fox", "10) …", "#3 …", "Step 2: …".
	 *
	 * A bare leading number ("2026 Hunting Seasons") does not count.
	 *
	 * @since 3.6.0
	 * @param string $text Heading text.
	 * @return bool
	 */
	public static function isNumbered( string $text ): bool {
		return 1 === preg_match( '/^\s*(#\d+\b|\d+[.):]\s|step\s+\d+\b)/i', $text );
	}

	/**
	 * Search phrase for a heading: numbering and brackets removed, characters
	 * unsafe in filenames dropped, and the article's US state appended when
	 * the heading does not name one. Saved as an image filename, it matches
	 * the heading on the next article run.
	 *
	 * @since 3.6.0
	 * @param string $heading Heading text.
	 * @param string $title   Article title.
	 * @return string
	 */
	public static function searchPhrase( string $heading, string $title ): string {
		$line = (string) preg_replace( '/^\s*(#\d+|\d+[.):]|step\s+\d+[.):]?)\s*/i', '', $heading );
		$line = (string) preg_replace( '/\s*\([^)]*\)\s*/', ' ', $line );
		$line = (string) preg_replace( '/[\\\\\/:*?"<>|]/', '', $line );
		$line = trim( (string) preg_replace( '/\s+/', ' ', $line ) );

		if ( '' === $line || array() !== RelevanceGuard::placesIn( $line ) ) {
			return $line;
		}

		$places = RelevanceGuard::placesIn( $title );
		if ( empty( $places ) ) {
			return $line;
		}

		return $line . ' in ' . ucwords( $places[0] );
	}

	// -------------------------------------------------------------------------
	// Index
	// -------------------------------------------------------------------------

	/**
	 * Headings in a post that have no image, minus ignored ones.
	 *
	 * @since 3.6.0
	 * @param \WP_Post $post    Post.
	 * @param string[] $ignored Heading hashes marked "not needed".
	 * @return array{total:int,all_missing:bool,heads:array<int,array{hash:string,level:int,text:string,numbered:bool,phrase:string}>}
	 */
	public function compute( \WP_Post $post, array $ignored ): array {
		$headings = $this->extractor->extract( (string) $post->post_content );
		$headings = $this->matcher->filterByHierarchy( $headings, (string) Settings::get( 'hierarchy_mode' ) );
		$missing  = empty( $headings ) ? array() : $this->insertion->headingsNeedingImages( (int) $post->ID, $headings );

		$heads = array();
		foreach ( $missing as $heading ) {
			$hash = (string) ( $heading['heading_hash'] ?? '' );
			if ( '' === $hash || in_array( $hash, $ignored, true ) ) {
				continue;
			}
			$text    = (string) ( $heading['text'] ?? '' );
			$heads[] = array(
				'hash'     => $hash,
				'level'    => (int) ( $heading['level'] ?? 2 ),
				'text'     => $text,
				'numbered' => self::isNumbered( $text ),
				'phrase'   => self::searchPhrase( $text, (string) $post->post_title ),
			);
		}

		return array(
			'total'       => count( $headings ),
			'all_missing' => count( $headings ) > 0 && count( $missing ) === count( $headings ),
			'heads'       => $heads,
		);
	}

	/**
	 * Recompute and store one post's entry (or remove it if out of scope).
	 *
	 * @since 3.6.0
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function indexPost( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post
			|| ! in_array( $post->post_type, self::POST_TYPES, true )
			|| ! in_array( $post->post_status, self::STATUSES, true )
			|| IgnoredArticles::isIgnored( $post_id ) ) {
			self::forget( $post_id );
			return;
		}

		$ignored = get_post_meta( $post_id, self::META_IGNORED, true );
		$data    = $this->compute( $post, is_array( $ignored ) ? array_map( 'strval', $ignored ) : array() );

		$numbered = 0;
		foreach ( $data['heads'] as $head ) {
			$numbered += $head['numbered'] ? 1 : 0;
		}

		update_post_meta( $post_id, self::META, $data );
		update_post_meta( $post_id, self::META_COUNT, count( $data['heads'] ) );
		update_post_meta( $post_id, self::META_NUMBERED, $numbered );
		update_post_meta( $post_id, self::META_ALL, $data['all_missing'] ? '1' : '0' );
	}

	/**
	 * Remove a post's entry.
	 *
	 * @since 3.6.0
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function forget( int $post_id ): void {
		foreach ( array( self::META, self::META_COUNT, self::META_NUMBERED, self::META_ALL ) as $key ) {
			delete_post_meta( $post_id, $key );
		}
	}

	/**
	 * Mark a heading "not needed" (or bring it back) and re-index the post.
	 *
	 * @since 3.6.0
	 * @param int    $post_id Post ID.
	 * @param string $hash    Heading hash.
	 * @param bool   $ignored Whether to hide it.
	 * @return void
	 */
	public function setIgnored( int $post_id, string $hash, bool $ignored ): void {
		$list = get_post_meta( $post_id, self::META_IGNORED, true );
		$list = is_array( $list ) ? array_map( 'strval', $list ) : array();
		$list = $ignored ? array_values( array_unique( array_merge( $list, array( $hash ) ) ) ) : array_values( array_diff( $list, array( $hash ) ) );

		if ( empty( $list ) ) {
			delete_post_meta( $post_id, self::META_IGNORED );
		} else {
			update_post_meta( $post_id, self::META_IGNORED, $list );
		}

		$this->indexPost( $post_id );
	}

	/**
	 * save_post: keep the entry fresh (also fires after every SIM insert).
	 *
	 * @since 3.6.0
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function onSavePost( $post_id ): void {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$this->indexPost( $post_id );
	}

	/**
	 * Index one bounded batch of posts after an ID cursor.
	 *
	 * @since 3.6.0
	 * @param int $after_id   Last post ID already indexed.
	 * @param int $batch_size Posts per batch.
	 * @return array{indexed:int,next_after:int,done:bool}
	 */
	public function scanBatch( int $after_id, int $batch_size = 100 ): array {
		global $wpdb;

		$types    = "'" . implode( "','", self::POST_TYPES ) . "'";
		$statuses = "'" . implode( "','", self::STATUSES ) . "'";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- constant IN lists, values prepared.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ({$types}) AND post_status IN ({$statuses}) ORDER BY ID ASC LIMIT %d", $after_id, $batch_size ) );
		$ids = array_map( 'intval', is_array( $ids ) ? $ids : array() );

		foreach ( $ids as $id ) {
			$this->indexPost( $id );
		}

		return array(
			'indexed'    => count( $ids ),
			'next_after' => empty( $ids ) ? $after_id : (int) end( $ids ),
			'done'       => count( $ids ) < $batch_size,
		);
	}

	/**
	 * Start (or restart) the full scan from the first post.
	 *
	 * @since 3.6.0
	 * @return void
	 */
	public static function armScan(): void {
		update_option(
			self::SCAN_STATE_OPTION,
			array(
				'after_id' => 0,
				'done'     => false,
				'indexed'  => 0,
			),
			false
		);
	}
}
