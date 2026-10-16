<?php
/**
 * Articles the user chose to ignore: no run (manual, scheduled, on publish)
 * touches them, and they leave Review and Missing Images.
 *
 * @package SmartImageMatcher\Domain
 * @since   3.7.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PSR-4 camelCase methods (see agents.md).

/**
 * Class IgnoredArticles
 *
 * @since 3.7.0
 */
class IgnoredArticles {

	/** Post meta flag ("1" when ignored). */
	const META = '_smart_image_matcher_ignored';

	/**
	 * Whether an article is ignored.
	 *
	 * @since 3.7.0
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function isIgnored( int $post_id ): bool {
		return $post_id > 0 && '1' === (string) get_post_meta( $post_id, self::META, true );
	}

	/**
	 * Ignore or un-ignore an article.
	 *
	 * Ignoring also clears its open Review suggestions.
	 *
	 * @since 3.7.0
	 * @param int  $post_id Post ID.
	 * @param bool $ignored Whether to ignore.
	 * @return void
	 */
	public static function set( int $post_id, bool $ignored ): void {
		if ( $post_id <= 0 ) {
			return;
		}

		if ( $ignored ) {
			update_post_meta( $post_id, self::META, '1' );
			( new MatchRepository() )->clearPendingForPost( $post_id );
			MissingImagesIndex::forget( $post_id );
			return;
		}

		delete_post_meta( $post_id, self::META );
	}

	/**
	 * Meta-query clause that leaves ignored articles out of a WP_Query.
	 *
	 * @since 3.7.0
	 * @return array<string, string>
	 */
	public static function excludeClause(): array {
		return array(
			'key'     => self::META,
			'compare' => 'NOT EXISTS',
		);
	}

	/**
	 * Ignored articles, newest first.
	 *
	 * @since 3.7.0
	 * @param int $limit Max rows.
	 * @return array<int, array{id:int,title:string,edit_url:string}>
	 */
	public static function all( int $limit = 200 ): array {
		$ids = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small, user-curated list.
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$out = array();
		foreach ( (array) $ids as $id ) {
			$id    = (int) $id;
			$out[] = array(
				'id'       => $id,
				'title'    => html_entity_decode( (string) get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
				'edit_url' => (string) get_edit_post_link( $id, 'raw' ),
			);
		}
		return $out;
	}
}
