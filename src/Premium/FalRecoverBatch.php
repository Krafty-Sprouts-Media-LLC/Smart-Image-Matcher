<?php
/**
 * Bulk recovery of fal queue results into WordPress media / featured images.
 *
 * @package SmartImageMatcher\Premium
 * @since   3.2.21
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Premium;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\AI\ImageModelCatalog;
use SmartImageMatcher\AI\ProviderBridge;
use SmartImageMatcher\Domain\Normalizer;
use SmartImageMatcher\Logging\Logger;
use SmartImageMatcher\Queue\Queue;
use SmartImageMatcher\Settings\Settings;

/**
 * Class FalRecoverBatch
 *
 * @since 3.2.21
 */
class FalRecoverBatch {

	/**
	 * Broad visual/context terms that cannot safely identify one article.
	 *
	 * @since 3.2.22
	 * @var string[]
	 */
	private const MATCH_NOISE_TOKENS = array(
		'food',
		'foods',
		'eye',
		'eyes',
		'fruit',
		'fruits',
		'garden',
		'health',
		'healthy',
		'home',
		'houseplant',
		'houseplants',
		'image',
		'kitchen',
		'living',
		'natural',
		'photo',
		'photograph',
		'plant',
		'plants',
		'realistic',
		'room',
	);

	/**
	 * Parse a CSV file into recovery rows.
	 *
	 * Accepted headers (case-insensitive): post_id, request_id, model_id
	 * Or headerless rows: post_id,request_id[,model_id]
	 * Or single-column request_id list (post matched later).
	 *
	 * @since 3.2.21
	 * @param string $path Absolute path.
	 * @return list<array{post_id:int,request_id:string,model_id:string}>|\WP_Error
	 */
	public static function parseCsvFile( string $path ) {
		if ( ! is_readable( $path ) ) {
			return new \WP_Error(
				'smart_image_matcher_csv_unreadable',
				__( 'CSV file is not readable.', 'smart-image-matcher' )
			);
		}

		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- admin/CLI recovery file.
		if ( false === $raw || '' === trim( $raw ) ) {
			return new \WP_Error(
				'smart_image_matcher_csv_empty',
				__( 'CSV file is empty.', 'smart-image-matcher' )
			);
		}

		return self::parseCsvString( $raw );
	}

	/**
	 * Parse CSV text into recovery rows.
	 *
	 * @since 3.2.21
	 * @param string $raw CSV contents.
	 * @return list<array{post_id:int,request_id:string,model_id:string}>|\WP_Error
	 */
	public static function parseCsvString( string $raw ) {
		$lines = preg_split( '/\R/', trim( $raw ) );
		if ( ! is_array( $lines ) || array() === $lines ) {
			return new \WP_Error(
				'smart_image_matcher_csv_empty',
				__( 'CSV content is empty.', 'smart-image-matcher' )
			);
		}

		$rows   = array();
		$header = null;

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}

			$cols = str_getcsv( $line );
			$cols = array_map( 'trim', $cols );

			if ( null === $header ) {
				$lower = array_map( 'strtolower', $cols );
				if ( in_array( 'request_id', $lower, true ) || in_array( 'post_id', $lower, true ) ) {
					$header = array();
					foreach ( $lower as $i => $name ) {
						$header[ $name ] = $i;
					}
					continue;
				}
			}

			if ( null !== $header ) {
				$post_id    = isset( $header['post_id'] ) ? absint( $cols[ $header['post_id'] ] ?? 0 ) : 0;
				$request_id = isset( $header['request_id'] ) ? sanitize_text_field( (string) ( $cols[ $header['request_id'] ] ?? '' ) ) : '';
				$model_id   = isset( $header['model_id'] ) ? sanitize_text_field( (string) ( $cols[ $header['model_id'] ] ?? '' ) ) : '';
			} elseif ( 1 === count( $cols ) ) {
				$post_id    = 0;
				$request_id = sanitize_text_field( $cols[0] );
				$model_id   = '';
			} else {
				$post_id    = absint( $cols[0] ?? 0 );
				$request_id = sanitize_text_field( (string) ( $cols[1] ?? '' ) );
				$model_id   = sanitize_text_field( (string) ( $cols[2] ?? '' ) );
			}

			if ( '' === $request_id ) {
				continue;
			}

			$rows[] = array(
				'post_id'    => $post_id,
				'request_id' => $request_id,
				'model_id'   => $model_id,
			);
		}

		if ( array() === $rows ) {
			return new \WP_Error(
				'smart_image_matcher_csv_no_rows',
				__( 'No recoverable rows found in CSV.', 'smart-image-matcher' )
			);
		}

		return $rows;
	}

	/**
	 * Parse a comma/whitespace-separated list of request ids.
	 *
	 * @since 3.2.21
	 * @param string $list Raw list.
	 * @return list<string>
	 */
	public static function parseRequestIdList( string $list ): array {
		$parts = preg_split( '/[\s,;]+/', $list );
		if ( ! is_array( $parts ) ) {
			return array();
		}
		$out = array();
		foreach ( $parts as $part ) {
			$id = sanitize_text_field( trim( $part ) );
			if ( '' !== $id ) {
				$out[] = $id;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Discover completed image requests directly from fal account history.
	 *
	 * No CSV or request-id entry is required. The returned prompt and output
	 * are used to match requests to WordPress posts missing featured images.
	 *
	 * @since 3.2.22
	 * @param int $hours Lookback window.
	 * @param int $limit Maximum requests.
	 * @return list<array<string, mixed>>|\WP_Error
	 */
	public static function discoverRecentRows( int $hours = 48, int $limit = 500 ) {
		$requests = ProviderBridge::listRecentFalImageRequests(
			ImageModelCatalog::allowedIds(),
			$hours,
			$limit
		);
		if ( is_wp_error( $requests ) ) {
			return $requests;
		}

		$rows = array();
		foreach ( $requests as $request ) {
			$request_id  = sanitize_text_field( (string) ( $request['request_id'] ?? '' ) );
			$model_id    = sanitize_text_field( (string) ( $request['endpoint_id'] ?? '' ) );
			$status_code = absint( $request['status_code'] ?? 0 );
			if (
				'' === $request_id
				|| ! ImageModelCatalog::isAllowed( $model_id )
				|| $status_code < 200
				|| $status_code >= 300
			) {
				continue;
			}

			$input  = isset( $request['json_input'] ) && is_array( $request['json_input'] )
				? $request['json_input']
				: array();
			$output = isset( $request['json_output'] ) && is_array( $request['json_output'] )
				? $request['json_output']
				: array();

			$source = self::extractImageSourceFromFalBody( $output );
			if ( null === $source ) {
				continue;
			}

			$rows[] = array(
				'post_id'    => 0,
				'request_id' => $request_id,
				'model_id'   => $model_id,
				'prompt'     => self::extractPromptFromFalBody( $input ),
				'source'     => $source,
				'sent_at'    => sanitize_text_field( (string) ( $request['sent_at'] ?? '' ) ),
			);
		}

		return $rows;
	}

	/**
	 * Score how well a post title appears in a fal prompt.
	 *
	 * @since 3.2.21
	 * @param string $title  Post title.
	 * @param string $prompt fal prompt text.
	 * @return int 0–100.
	 */
	public static function scoreTitleInPrompt( string $title, string $prompt ): int {
		$title_tokens  = array_values(
			array_diff(
				array_unique( Normalizer::normalize( wp_strip_all_tags( $title ) ) ),
				self::MATCH_NOISE_TOKENS
			)
		);
		$prompt_tokens = array_values(
			array_diff(
				array_unique( Normalizer::normalize( wp_strip_all_tags( $prompt ) ) ),
				self::MATCH_NOISE_TOKENS
			)
		);
		if ( count( $title_tokens ) < 2 || array() === $prompt_tokens ) {
			return 0;
		}

		$hits = count( array_intersect( $title_tokens, $prompt_tokens ) );
		return (int) round( 100 * ( $hits / count( $title_tokens ) ) );
	}

	/**
	 * Score a post title/focus keyword against an image prompt.
	 *
	 * Body text is deliberately not used as a fallback: broad article content
	 * produced false-positive recovery matches. A missed match is safer than
	 * assigning a paid image to the wrong article.
	 *
	 * @since 3.2.22
	 * @param string $title   Post title.
	 * @param string $content Reserved for compatibility.
	 * @param string $prompt  fal image prompt.
	 * @param string $focus   SEO focus keyword.
	 * @return int 0–100.
	 */
	public static function scorePostAgainstPrompt( string $title, string $content, string $prompt, string $focus = '' ): int {
		unset( $content );
		return max(
			self::scoreTitleInPrompt( $title, $prompt ),
			self::scoreTitleInPrompt( $focus, $prompt )
		);
	}

	/**
	 * Pick best unmatched candidate post for a prompt.
	 *
	 * @since 3.2.21
	 * @param string           $prompt      fal prompt.
	 * @param array<int,mixed> $candidates  post_id => title or title/content.
	 * @param array<int,true>  $used        Already assigned post ids.
	 * @param int              $min_score   Minimum score.
	 * @return int Post ID or 0.
	 */
	public static function matchPromptToPost( string $prompt, array $candidates, array $used, int $min_score = 60 ): int {
		$best_id    = 0;
		$best_score = 0;
		foreach ( $candidates as $post_id => $candidate ) {
			$post_id = (int) $post_id;
			if ( isset( $used[ $post_id ] ) ) {
				continue;
			}
			if ( is_array( $candidate ) ) {
				$title   = (string) ( $candidate['title'] ?? '' );
				$content = (string) ( $candidate['content'] ?? '' );
				$focus   = (string) ( $candidate['focus'] ?? '' );
				$score   = self::scorePostAgainstPrompt( $title, $content, $prompt, $focus );
			} else {
				$score = self::scoreTitleInPrompt( (string) $candidate, $prompt );
			}
			if ( $score > $best_score ) {
				$best_score = $score;
				$best_id    = $post_id;
			}
		}
		return ( $best_score >= $min_score ) ? $best_id : 0;
	}

	/**
	 * Preview automatic prompt-to-post assignments without importing images.
	 *
	 * @since 3.2.22
	 * @param list<array<string, mixed>> $rows      Discovered fal rows.
	 * @param int                        $min_score Minimum match score.
	 * @return array{matched:list<array<string,mixed>>,unmatched:list<array<string,mixed>>}
	 */
	public static function previewMatches( array $rows, int $min_score = 60 ): array {
		$candidates = self::loadCandidatePosts();
		$used       = array();
		$matched    = array();
		$unmatched  = array();

		foreach ( $rows as $row ) {
			$prompt  = sanitize_textarea_field( (string) ( $row['prompt'] ?? '' ) );
			$post_id = self::matchPromptToPost( $prompt, $candidates, $used, $min_score );
			$preview = array(
				'request_id' => sanitize_text_field( (string) ( $row['request_id'] ?? '' ) ),
				'model_id'   => sanitize_text_field( (string) ( $row['model_id'] ?? '' ) ),
				'prompt'     => $prompt,
				'post_id'    => $post_id,
				'post_title' => $post_id > 0 ? (string) get_the_title( $post_id ) : '',
			);

			if ( $post_id > 0 ) {
				$used[ $post_id ] = true;
				$matched[]        = $preview;
			} else {
				$unmatched[] = $preview;
			}
		}

		return array(
			'matched'   => $matched,
			'unmatched' => $unmatched,
		);
	}

	/**
	 * Queue previewed matches for background recovery.
	 *
	 * @since 3.2.23
	 * @param list<array<string,mixed>> $rows         Discovered fal rows.
	 * @param list<array<string,mixed>> $matched      Previewed matches.
	 * @param string                    $heading_hash Heading hash.
	 * @return array{jobs:list<array<string,mixed>>,failed:list<array<string,mixed>>,skipped:int}
	 */
	public static function queueMatched( array $rows, array $matched, string $heading_hash = 'featured' ): array {
		$heading_hash = sanitize_text_field( $heading_hash );
		if ( '' === $heading_hash ) {
			$heading_hash = 'featured';
		}

		$rows_by_request = array();
		foreach ( $rows as $row ) {
			$request_id = sanitize_text_field( (string) ( $row['request_id'] ?? '' ) );
			if ( '' !== $request_id ) {
				$rows_by_request[ $request_id ] = $row;
			}
		}

		$queue   = new Queue();
		$jobs    = array();
		$failed  = array();
		$skipped = 0;

		foreach ( $matched as $match ) {
			$request_id = sanitize_text_field( (string) ( $match['request_id'] ?? '' ) );
			$post_id    = absint( $match['post_id'] ?? 0 );
			$row        = $rows_by_request[ $request_id ] ?? null;
			if ( '' === $request_id || $post_id <= 0 || ! is_array( $row ) ) {
				++$skipped;
				continue;
			}

			$model_id = sanitize_text_field( (string) ( $row['model_id'] ?? '' ) );
			$prompt   = sanitize_textarea_field( (string) ( $row['prompt'] ?? '' ) );
			$source   = isset( $row['source'] ) && is_array( $row['source'] ) ? $row['source'] : null;
			if ( null === $source ) {
				$failed[] = array(
					'request_id' => $request_id,
					'post_id'    => $post_id,
					'error'      => __( 'The completed fal image source was unavailable.', 'smart-image-matcher' ),
				);
				continue;
			}

			$pending = array(
				'fal'     => array(
					'request_id' => $request_id,
					'model_id'   => $model_id,
				),
				'source'  => $source,
				'context' => array(
					'post_id'      => $post_id,
					'heading_hash' => $heading_hash,
					'heading_text' => get_the_title( $post_id ),
					'purpose'      => ( 'featured' === $heading_hash ) ? 'featured' : 'heading',
					'style'        => 'photo',
					'image_prompt' => $prompt,
					'brief'        => $prompt,
				),
			);

			AiImageGenerator::storeFalPending( $post_id, $heading_hash, $pending );
			AiImageGenerator::setStatus(
				$post_id,
				$heading_hash,
				array(
					'status'  => 'queued',
					'message' => __( 'Completed fal image queued for recovery.', 'smart-image-matcher' ),
					'fal'     => $pending['fal'],
					'context' => $pending['context'],
				)
			);

			$action_id = $queue->enqueueFalRecovery( $post_id, $heading_hash );
			if ( null === $action_id ) {
				$failed[] = array(
					'request_id' => $request_id,
					'post_id'    => $post_id,
					'error'      => __( 'Could not enqueue the fal recovery job.', 'smart-image-matcher' ),
				);
				continue;
			}

			$jobs[] = array(
				'action_id'    => $action_id,
				'request_id'   => $request_id,
				'post_id'      => $post_id,
				'heading_hash' => $heading_hash,
			);
		}

		return array(
			'jobs'    => $jobs,
			'failed'  => $failed,
			'skipped' => $skipped,
		);
	}

	/**
	 * Run a bulk recovery.
	 *
	 * @since 3.2.21
	 * @param list<array{post_id?:int,request_id:string,model_id?:string}> $rows       Rows to recover.
	 * @param array<string,mixed>                                          $options    Options.
	 * @return array{recovered:list<array<string,mixed>>,failed:list<array<string,mixed>>,skipped:int}
	 */
	public static function run( array $rows, array $options = array() ): array {
		$heading_hash  = sanitize_text_field( (string) ( $options['heading_hash'] ?? 'featured' ) );
		$default_model = sanitize_text_field( (string) ( $options['model_id'] ?? '' ) );
		if ( '' === $default_model ) {
			$default_model = (string) Settings::get( 'ai_image_model' );
		}
		if ( ! ImageModelCatalog::isAllowed( $default_model ) ) {
			$default_model = ImageModelCatalog::DEFAULT_MODEL_ID;
		}

		$match_posts = ! empty( $options['match_posts'] );
		$unattached  = ! empty( $options['unattached'] );
		$min_score   = isset( $options['min_score'] ) ? absint( $options['min_score'] ) : 60;

		$candidates = array();
		if ( $match_posts ) {
			$candidates = self::loadCandidatePosts();
		}

		$used      = array();
		$recovered = array();
		$failed    = array();
		$skipped   = 0;

		foreach ( $rows as $row ) {
			$request_id = sanitize_text_field( (string) ( $row['request_id'] ?? '' ) );
			$model_id   = sanitize_text_field( (string) ( $row['model_id'] ?? '' ) );
			$post_id    = absint( $row['post_id'] ?? 0 );
			$prompt     = sanitize_textarea_field( (string) ( $row['prompt'] ?? '' ) );
			if ( '' === $model_id ) {
				$model_id = $default_model;
			}
			if ( '' === $request_id ) {
				++$skipped;
				continue;
			}

			$source = isset( $row['source'] ) && is_array( $row['source'] )
				? $row['source']
				: ProviderBridge::fetchImageByRequestId( $model_id, $request_id );
			if ( is_wp_error( $source ) ) {
				$failed[] = array(
					'request_id' => $request_id,
					'post_id'    => $post_id,
					'error'      => $source->get_error_message(),
				);
				continue;
			}

			if ( $match_posts && 0 === $post_id ) {
				if ( '' === $prompt ) {
					$prompt = self::fetchPromptForRequest( $model_id, $request_id );
				}
				$post_id = self::matchPromptToPost( $prompt, $candidates, $used, $min_score );
				if ( $post_id <= 0 && ! $unattached ) {
					$failed[] = array(
						'request_id' => $request_id,
						'post_id'    => 0,
						'error'      => __( 'Could not match fal prompt to a post without a featured image.', 'smart-image-matcher' ),
						'prompt'     => function_exists( 'mb_substr' ) ? mb_substr( $prompt, 0, 120 ) : substr( $prompt, 0, 120 ),
					);
					continue;
				}
			}

			if ( $post_id <= 0 && $unattached ) {
				$attachment_id = self::sideloadUnattached( $source, $request_id );
				if ( is_wp_error( $attachment_id ) ) {
					$failed[] = array(
						'request_id' => $request_id,
						'post_id'    => 0,
						'error'      => $attachment_id->get_error_message(),
					);
					continue;
				}
				$recovered[] = array(
					'request_id'    => $request_id,
					'post_id'       => 0,
					'attachment_id' => (int) $attachment_id,
					'unattached'    => true,
				);
				continue;
			}

			if ( $post_id <= 0 ) {
				$failed[] = array(
					'request_id' => $request_id,
					'post_id'    => 0,
					'error'      => __( 'No post_id and matching/unattached not enabled.', 'smart-image-matcher' ),
				);
				continue;
			}

			$pending = array(
				'fal'     => array(
					'request_id' => $request_id,
					'model_id'   => $model_id,
				),
				'source'  => $source,
				'context' => array(
					'post_id'      => $post_id,
					'heading_hash' => $heading_hash,
					'heading_text' => get_the_title( $post_id ),
					'purpose'      => ( 'featured' === $heading_hash ) ? 'featured' : 'heading',
					'style'        => 'photo',
					'image_prompt' => $prompt,
					'brief'        => $prompt,
				),
			);

			$result = AiImageGenerator::recoverFalJob( $post_id, $heading_hash, $pending );
			if ( is_wp_error( $result ) ) {
				$failed[] = array(
					'request_id' => $request_id,
					'post_id'    => $post_id,
					'error'      => $result->get_error_message(),
				);
				continue;
			}

			$used[ $post_id ] = true;
			$recovered[]      = array(
				'request_id'    => $request_id,
				'post_id'       => $post_id,
				'attachment_id' => (int) $result,
			);
		}

		Logger::info(
			'FalRecoverBatch: finished',
			array(
				'recovered' => count( $recovered ),
				'failed'    => count( $failed ),
				'skipped'   => $skipped,
			)
		);

		return array(
			'recovered' => $recovered,
			'failed'    => $failed,
			'skipped'   => $skipped,
		);
	}

	/**
	 * Candidate posts: public types supporting thumbnails, missing featured.
	 *
	 * @since 3.2.21
	 * @return array<int,array{title:string,content:string,focus:string}>
	 */
	private static function loadCandidatePosts(): array {
		$types = get_post_types( array( 'public' => true ), 'names' );
		$out   = array();
		$page  = 1;

		do {
			$q = new \WP_Query(
				array(
					'post_type'              => $types,
					'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'posts_per_page'         => 500,
					'paged'                  => $page,
					'no_found_rows'          => true,
					'update_post_meta_cache' => true,
					'update_post_term_cache' => false,
				)
			);

			foreach ( $q->posts as $post ) {
				if ( ! $post instanceof \WP_Post ) {
					continue;
				}
				$id = (int) $post->ID;
				if ( ! post_type_supports( $post->post_type, 'thumbnail' ) ) {
					continue;
				}
				if ( has_post_thumbnail( $id ) ) {
					continue;
				}
				if ( ! current_user_can( 'edit_post', $id ) ) {
					continue;
				}
				$out[ $id ] = array(
					'title'   => (string) $post->post_title,
					'content' => '',
					'focus'   => \SmartImageMatcher\AI\PromptBuilder::getFocusKeyword( $id ),
				);
			}

			++$page;
			$page_count = count( $q->posts );
			$out_count  = count( $out );
		} while ( 500 === $page_count && $out_count < 5000 );

		return $out;
	}

	/**
	 * Best-effort prompt fetch for matching (raw fal result body).
	 *
	 * @since 3.2.21
	 * @param string $model_id   Model.
	 * @param string $request_id Request.
	 * @return string
	 */
	private static function fetchPromptForRequest( string $model_id, string $request_id ): string {
		if ( ! class_exists( '\KraftySprouts\AiProviderForFalAi\Queue\FalQueueClient' ) ) {
			return '';
		}
		// Reuse fetchByRequestId path's underlying GET via a filter-friendly hook later; for now empty.
		// Prompt is often in the same JSON as images — extend client when needed.
		$url  = \KraftySprouts\AiProviderForFalAi\Provider\FalProvider::queueUrl(
			ltrim( $model_id, '/' ) . '/requests/' . rawurlencode( $request_id )
		);
		$body = \KraftySprouts\AiProviderForFalAi\Queue\FalQueueClient::fetchRawResult( $url );
		if ( is_wp_error( $body ) || ! is_array( $body ) ) {
			return '';
		}
		return self::extractPromptFromFalBody( $body );
	}

	/**
	 * Pull prompt text from a fal result/status payload.
	 *
	 * @since 3.2.22
	 * @param array<string, mixed> $body Decoded fal JSON.
	 * @return string
	 */
	public static function extractPromptFromFalBody( array $body ): string {
		$candidates = array(
			$body['prompt'] ?? null,
			( isset( $body['input'] ) && is_array( $body['input'] ) ) ? ( $body['input']['prompt'] ?? null ) : null,
			( isset( $body['request'] ) && is_array( $body['request'] ) ) ? ( $body['request']['prompt'] ?? null ) : null,
			( isset( $body['payload'] ) && is_array( $body['payload'] ) ) ? ( $body['payload']['prompt'] ?? null ) : null,
		);
		foreach ( $candidates as $value ) {
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}
		return '';
	}

	/**
	 * Pull the first image source from a fal output payload.
	 *
	 * @since 3.2.22
	 * @param array<string, mixed> $body Decoded fal output JSON.
	 * @return array{url:string,mime:string}|null
	 */
	public static function extractImageSourceFromFalBody( array $body ): ?array {
		$images = array();
		if ( isset( $body['images'] ) && is_array( $body['images'] ) ) {
			$images = array_values( $body['images'] );
		} elseif ( isset( $body['image'] ) && is_array( $body['image'] ) ) {
			$images = array( $body['image'] );
		}
		if ( empty( $images ) || ! is_array( $images[0] ) ) {
			return null;
		}

		$url = isset( $images[0]['url'] ) && is_string( $images[0]['url'] )
			? esc_url_raw( $images[0]['url'] )
			: '';
		if ( '' === $url ) {
			return null;
		}

		$mime = 'image/jpeg';
		if ( isset( $images[0]['content_type'] ) && is_string( $images[0]['content_type'] ) ) {
			$mime = sanitize_mime_type( $images[0]['content_type'] );
		}

		return array(
			'url'  => $url,
			'mime' => $mime,
		);
	}

	/**
	 * Sideload without assigning a parent post.
	 *
	 * @since 3.2.21
	 * @param array{url?:string,mime?:string} $source     Image source.
	 * @param string                          $request_id Request id for title.
	 * @return int|\WP_Error
	 */
	private static function sideloadUnattached( array $source, string $request_id ) {
		$generator = new AiImageGenerator();
		$context   = array(
			'post_id'       => 0,
			'heading_hash'  => 'featured',
			'heading_text'  => 'fal-recover-' . $request_id,
			'purpose'       => 'featured',
			'style'         => 'photo',
			'image_prompt'  => '',
			'brief'         => '',
			'focus_keyword' => '',
			'section_text'  => '',
			'taxonomy_hint' => '',
		);
		// finalizeFromSource requires post_id for sideload parent — use 0 (media library root).
		return $generator->finalizeFromSource( $source, $context );
	}
}
