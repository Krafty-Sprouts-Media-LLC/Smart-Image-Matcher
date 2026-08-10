/**
 * generate-images.js - AI Generate Featured Images (Featured Images admin card).
 *
 * Featured-image only. Heading images stay in the post editor modal.
 *
 * Smart Image Matcher.
 * @since   3.2.0
 */

/* eslint-disable @wordpress/valid-sprintf, no-alert */

( function () {
	'use strict';

	const config = window.smartImageMatcherGenerateImages || {};
	const apiFetch = window.wp && window.wp.apiFetch;
	const nonce = config.nonce || '';
	const prefillPostIds = Array.isArray( config.prefillPostIds )
		? config.prefillPostIds
		: [];
	const generationReady = !! config.generationReady;
	const editPostUrl = config.editPostUrl || 'post.php?post=%d&action=edit';
	const focusAi = !! config.focusAi;

	const i18n = Object.assign(
		{
			scanning: 'Scanning posts…',
			scanFailed: 'Could not scan posts.',
			noStatuses: 'Select at least one post status before scanning.',
			noResults:
				'No posts need a featured image for the current filters.',
			scanComplete: 'Scan complete.',
			confirmGenerate:
				'Generate %d featured image(s)? Time varies by model — often a few minutes each.',
			generating: 'Queueing generation jobs…',
			generateFailed: 'Could not queue generation jobs.',
			generateComplete: 'All jobs finished.',
			progress: 'Completed %1$d of %2$d',
			noApi: 'Generate Featured Images controls could not load because wp.apiFetch is unavailable.',
			minute: 'minute',
			minutes: 'minutes',
			second: 'second',
			seconds: 'seconds',
			queued: 'Queued',
			processing: 'Processing',
			completed: 'Completed',
			failed: 'Failed',
			edit: 'Edit',
			needsFeatured: 'Missing featured image',
			alreadyHasFeatured: 'Already has featured image',
			noThumbnailSupport: 'Post type does not support featured images',
			alreadyGenerated: 'Already generated for this style',
			rejected: 'Previously rejected',
			notFound: 'Post not found',
			noPermission: 'No permission',
			skippedOther: 'Skipped',
			estimateHint:
				'Usually a few minutes per image (varies by model and queue).',
			imagesCount: '%d image(s)',
			recoveryPreviewing: 'Checking recent completed fal.ai images…',
			recoveryPreviewFailed: 'Could not preview fal.ai recovery.',
			recoveryCriticalError:
				'WordPress hit a critical error while previewing recovery. Update AI Provider for fal.ai to 1.1.11+, then try again. If it still fails, check wp-content/debug.log.',
			recoveryPreviewComplete:
				'%1$d safe match(es) found; %2$d will remain untouched.',
			noRecoveryMatches:
				'No safe matches were found. Nothing will be imported.',
			confirmRecovery:
				'Recover %d matched image(s) into WordPress? Unmatched images will not be imported.',
			recoveryQueueing:
				'Queueing matched images for background recovery…',
			recoveryQueueFailed: 'Could not queue fal.ai recovery.',
			recoveryQueued: '%d image(s) queued for recovery.',
			recoveryProgress: 'Recovered %1$d of %2$d',
			recoveryComplete: 'All matched images were recovered.',
			recoveryCompleteWithFailures:
				'Recovery finished with %d failure(s).',
			recoveryMatched: 'Safe match',
			recoveryUnmatched: 'Not matched',
			recovering: 'Recovering',
		},
		config.i18n || {}
	);

	if ( apiFetch && apiFetch.createNonceMiddleware && nonce ) {
		apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );
	}

	let scanResult = null;
	let activeJobs = [];
	let pollTotal = 0;
	let succeededCount = 0;
	let failedCount = 0;
	let pollTimer = null;
	let recoveryPreview = null;
	let recoveryJobs = [];
	let recoveryPollTimer = null;
	let recoverySucceeded = 0;
	let recoveryFailed = 0;

	function q( selector ) {
		return document.querySelector( selector );
	}

	function qa( selector ) {
		return Array.from( document.querySelectorAll( selector ) );
	}

	function escHtml( value ) {
		const div = document.createElement( 'div' );
		div.textContent = String( value || '' );
		return div.innerHTML;
	}

	function sprintf( template, ...args ) {
		let i = 0;
		return String( template )
			.replace( /%(\d+)\$[ds]/g, ( match, index ) => {
				const value = args[ parseInt( index, 10 ) - 1 ];
				return null === value || undefined === value
					? ''
					: String( value );
			} )
			.replace( /%[ds]/g, () => {
				const value = args[ i++ ];
				return null === value || undefined === value
					? ''
					: String( value );
			} );
	}

	function reasonLabel( reason ) {
		const map = {
			already_has_featured: i18n.alreadyHasFeatured,
			no_thumbnail_support: i18n.noThumbnailSupport,
			already_generated: i18n.alreadyGenerated,
			rejected: i18n.rejected,
			not_found: i18n.notFound,
			no_permission: i18n.noPermission,
		};
		return map[ reason ] || i18n.skippedOther;
	}

	function showNotice( type, message ) {
		const notice = q( '#sim-generate-notice' );
		if ( ! notice ) {
			return;
		}
		notice.innerHTML = `<div class="notice notice-${ escHtml(
			type
		) } inline"><p>${ escHtml( message ) }</p></div>`;
	}

	function clearNotice() {
		const notice = q( '#sim-generate-notice' );
		if ( notice ) {
			notice.innerHTML = '';
		}
	}

	function showRecoveryNotice( type, message ) {
		const notice = q( '#sim-fal-recovery-notice' );
		if ( ! notice ) {
			return;
		}
		notice.innerHTML = `<div class="notice notice-${ escHtml(
			type
		) } inline"><p>${ escHtml( message ) }</p></div>`;
	}

	function setRecoveryWorking( working ) {
		const previewBtn = q( '#sim-fal-recovery-preview-button' );
		const runBtn = q( '#sim-fal-recovery-run-button' );
		const hours = q( '#sim-fal-recovery-hours' );
		const matched =
			recoveryPreview && Array.isArray( recoveryPreview.matched )
				? recoveryPreview.matched.length
				: 0;

		if ( previewBtn ) {
			previewBtn.disabled = working;
		}
		if ( hours ) {
			hours.disabled = working;
		}
		if ( runBtn ) {
			const canRun = ! working && matched > 0;
			runBtn.disabled = ! canRun;
			runBtn.setAttribute( 'aria-disabled', canRun ? 'false' : 'true' );
		}
	}

	function recoveryHours() {
		const select = q( '#sim-fal-recovery-hours' );
		return select ? parseInt( select.value || 48, 10 ) : 48;
	}

	function recoveryPrompt( value ) {
		const prompt = String( value || '' );
		return prompt.length > 140 ? `${ prompt.slice( 0, 137 ) }…` : prompt;
	}

	function renderRecoveryPreview( result ) {
		const matched = Array.isArray( result.matched ) ? result.matched : [];
		const unmatched = Array.isArray( result.unmatched )
			? result.unmatched
			: [];
		const summary = q( '#sim-fal-recovery-summary' );
		const matchedEl = q( '#sim-fal-recovery-matched' );
		const unmatchedEl = q( '#sim-fal-recovery-unmatched' );
		const table = q( '#sim-fal-recovery-table' );
		const body = q( '#sim-fal-recovery-body' );

		if ( summary ) {
			summary.hidden = false;
		}
		if ( matchedEl ) {
			matchedEl.textContent = String( matched.length );
		}
		if ( unmatchedEl ) {
			unmatchedEl.textContent = String( unmatched.length );
		}
		if ( ! table || ! body ) {
			return;
		}

		const rows = [];
		matched.forEach( ( item ) => {
			rows.push( `
				<tr data-recovery-post="${ parseInt( item.post_id || 0, 10 ) }">
					<td>${ escHtml( item.post_title || `#${ item.post_id }` ) }</td>
					<td>${ escHtml( item.request_id || '' ) }</td>
					<td>${ escHtml( recoveryPrompt( item.prompt ) ) }</td>
					<td class="sim-recovery-status"><span class="sim-status sim-status-good">${ escHtml(
						i18n.recoveryMatched
					) }</span></td>
				</tr>` );
		} );
		unmatched.forEach( ( item ) => {
			const bits = [];
			if ( 'number' === typeof item.score && item.score > 0 ) {
				bits.push( `${ item.score }%` );
			}
			if ( item.near_post_title ) {
				bits.push( `nearest: ${ item.near_post_title }` );
			}
			const detail = bits.length ? ` (${ bits.join( ' · ' ) })` : '';
			rows.push( `
				<tr>
					<td>—</td>
					<td>${ escHtml( item.request_id || '' ) }</td>
					<td>${ escHtml( recoveryPrompt( item.prompt ) ) }</td>
					<td><span class="sim-status sim-status-warn">${ escHtml(
						i18n.recoveryUnmatched + detail
					) }</span></td>
				</tr>` );
		} );

		body.innerHTML = rows.join( '' );
		table.hidden = 0 === rows.length;
	}

	function setRecoveryRowStatus( postId, label, state ) {
		const row = q(
			`[data-recovery-post="${ parseInt( postId || 0, 10 ) }"]`
		);
		const cell = row ? row.querySelector( '.sim-recovery-status' ) : null;
		if ( ! cell ) {
			return;
		}
		let statusClass = 'sim-status-info';
		if ( 'done' === state ) {
			statusClass = 'sim-status-good';
		} else if ( 'failed' === state ) {
			statusClass = 'sim-status-warn';
		}
		cell.innerHTML = `<span class="sim-status ${ statusClass }">${ escHtml(
			label
		) }</span>`;
	}

	function stopRecoveryPolling() {
		if ( recoveryPollTimer ) {
			window.clearTimeout( recoveryPollTimer );
			recoveryPollTimer = null;
		}
	}

	async function pollRecoveryJobs() {
		const stillActive = [];

		for ( const job of recoveryJobs ) {
			try {
				const status = await apiFetch( {
					path: `/smart-image-matcher/v1/generate-image/status?post_id=${
						job.post_id
					}&heading_hash=${ encodeURIComponent(
						job.heading_hash || 'featured'
					) }`,
					method: 'GET',
				} );
				const state = status.status || 'processing';
				if (
					'done' === state ||
					'completed' === state ||
					'exists' === state
				) {
					++recoverySucceeded;
					setRecoveryRowStatus( job.post_id, i18n.completed, 'done' );
				} else if ( 'failed' === state || 'error' === state ) {
					++recoveryFailed;
					setRecoveryRowStatus( job.post_id, i18n.failed, 'failed' );
				} else {
					stillActive.push( job );
					setRecoveryRowStatus(
						job.post_id,
						i18n.recovering,
						'processing'
					);
				}
			} catch ( err ) {
				stillActive.push( job );
			}
		}

		recoveryJobs = stillActive;
		const total = recoverySucceeded + recoveryFailed + recoveryJobs.length;
		const done = recoverySucceeded + recoveryFailed;
		showRecoveryNotice(
			'info',
			sprintf( i18n.recoveryProgress, done, total )
		);

		if ( recoveryJobs.length ) {
			recoveryPollTimer = window.setTimeout( pollRecoveryJobs, 3000 );
			return;
		}

		setRecoveryWorking( false );
		showRecoveryNotice(
			recoveryFailed > 0 ? 'warning' : 'success',
			recoveryFailed > 0
				? sprintf( i18n.recoveryCompleteWithFailures, recoveryFailed )
				: i18n.recoveryComplete
		);
	}

	function apiErrorMessage( err, fallback ) {
		const raw = String(
			( err && ( err.message || err.statusText ) ) || fallback || ''
		);
		if (
			/critical error/i.test( raw ) ||
			/<p>There has been a critical error/i.test( raw )
		) {
			return i18n.recoveryCriticalError;
		}
		return raw || fallback;
	}

	async function previewRecovery() {
		stopRecoveryPolling();
		recoveryPreview = null;
		setRecoveryWorking( true );
		showRecoveryNotice( 'info', i18n.recoveryPreviewing );

		try {
			const result = await apiFetch( {
				path: '/smart-image-matcher/v1/generate-images/recover',
				method: 'POST',
				data: {
					discover_recent: true,
					dry_run: true,
					hours: recoveryHours(),
				},
			} );
			recoveryPreview = result;
			renderRecoveryPreview( result );

			const matched = Array.isArray( result.matched )
				? result.matched.length
				: 0;
			const unmatched = Array.isArray( result.unmatched )
				? result.unmatched.length
				: 0;
			showRecoveryNotice(
				matched > 0 ? 'success' : 'warning',
				matched > 0
					? sprintf(
							i18n.recoveryPreviewComplete,
							matched,
							unmatched
					  )
					: i18n.noRecoveryMatches
			);
		} catch ( err ) {
			showRecoveryNotice(
				'error',
				apiErrorMessage( err, i18n.recoveryPreviewFailed )
			);
		} finally {
			setRecoveryWorking( false );
		}
	}

	async function runRecovery() {
		const matched =
			recoveryPreview && Array.isArray( recoveryPreview.matched )
				? recoveryPreview.matched.length
				: 0;
		if (
			matched <= 0 ||
			! window.confirm( sprintf( i18n.confirmRecovery, matched ) )
		) {
			return;
		}

		stopRecoveryPolling();
		setRecoveryWorking( true );
		showRecoveryNotice( 'info', i18n.recoveryQueueing );

		try {
			const result = await apiFetch( {
				path: '/smart-image-matcher/v1/generate-images/recover',
				method: 'POST',
				data: {
					discover_recent: true,
					hours: recoveryHours(),
				},
			} );

			recoveryJobs = Array.isArray( result.jobs ) ? result.jobs : [];
			recoverySucceeded = 0;
			recoveryFailed = Array.isArray( result.failed )
				? result.failed.length
				: 0;
			recoveryPreview = null;
			setRecoveryWorking( true );

			recoveryJobs.forEach( ( job ) => {
				setRecoveryRowStatus( job.post_id, i18n.queued, 'processing' );
			} );

			if ( ! recoveryJobs.length ) {
				setRecoveryWorking( false );
				showRecoveryNotice( 'error', i18n.recoveryQueueFailed );
				return;
			}

			showRecoveryNotice(
				'info',
				sprintf( i18n.recoveryQueued, recoveryJobs.length )
			);
			pollRecoveryJobs();
		} catch ( err ) {
			setRecoveryWorking( false );
			showRecoveryNotice(
				'error',
				err.message || i18n.recoveryQueueFailed
			);
		}
	}

	function collectFormData() {
		const postType = q( '#sim-generate-post-type' );
		const style = q( '#sim-generate-style' );
		const maxPosts = q( '#sim-generate-max-posts' );
		const statuses = qa( 'input[name="post_statuses[]"]:checked' ).map(
			( input ) => input.value
		);

		return {
			post_type: postType ? postType.value : 'post',
			post_statuses: statuses.length ? statuses : [ 'publish' ],
			post_ids: prefillPostIds.length ? prefillPostIds : [],
			max_posts: maxPosts ? parseInt( maxPosts.value || 100, 10 ) : 100,
			style: style ? style.value : 'photo',
		};
	}

	function setGenerateReady( genBtn, enabled ) {
		if ( ! genBtn ) {
			return;
		}
		genBtn.disabled = ! enabled;
		genBtn.setAttribute( 'aria-disabled', enabled ? 'false' : 'true' );
		genBtn.classList.toggle( 'button-primary', enabled );
		const scanBtn = q( '#sim-generate-scan-button' );
		if ( scanBtn && ! scanBtn.disabled ) {
			scanBtn.classList.toggle( 'button-primary', ! enabled );
		}
	}

	function setWorking( working ) {
		const scanBtn = q( '#sim-generate-scan-button' );
		const genBtn = q( '#sim-generate-all-button' );
		const postType = q( '#sim-generate-post-type' );
		const maxPosts = q( '#sim-generate-max-posts' );
		const style = q( '#sim-generate-style' );

		if ( scanBtn ) {
			scanBtn.disabled = working;
		}
		if ( genBtn ) {
			const canGenerate =
				! working &&
				!! scanResult &&
				parseInt( scanResult.total_images || 0, 10 ) > 0 &&
				generationReady;
			setGenerateReady( genBtn, canGenerate );
		}
		if ( postType && ! prefillPostIds.length ) {
			postType.disabled = working;
		}
		if ( maxPosts && ! prefillPostIds.length ) {
			maxPosts.disabled = working;
		}
		if ( style ) {
			style.disabled = working;
		}
		qa( 'input[name="post_statuses[]"]' ).forEach( ( input ) => {
			input.disabled = working;
		} );
	}

	function renderScanResults( result ) {
		const estimate = q( '#sim-generate-estimate' );
		const table = q( '#sim-generate-results-table' );
		const body = q( '#sim-generate-results-body' );

		if ( ! estimate || ! table || ! body ) {
			return;
		}

		const totalEl = q( '#sim-generate-total-images' );
		const timeEl = q( '#sim-generate-estimate-time' );
		const total = parseInt( result.total_images || 0, 10 );
		const posts = Array.isArray( result.posts ) ? result.posts : [];
		const skipped = Array.isArray( result.skipped ) ? result.skipped : [];

		estimate.style.display = 'block';
		if ( totalEl ) {
			totalEl.textContent = String( total );
		}
		if ( timeEl ) {
			timeEl.textContent = result.estimate_hint || i18n.estimateHint;
		}

		const rows = [];

		posts.forEach( ( post ) => {
			const editUrl = post.id
				? editPostUrl.replace( '%d', String( post.id ) )
				: '#';
			rows.push( `
				<tr>
					<td>${ escHtml( post.title || `#${ post.id }` ) }</td>
					<td>${ escHtml( i18n.needsFeatured ) }</td>
					<td><a href="${ escHtml(
						editUrl
					) }" target="_blank" rel="noopener noreferrer">${ escHtml(
						i18n.edit
					) }</a></td>
				</tr>` );
		} );

		skipped.forEach( ( item ) => {
			const editUrl = item.id
				? editPostUrl.replace( '%d', String( item.id ) )
				: '#';
			rows.push( `
				<tr>
					<td>${ escHtml( item.title || `#${ item.id }` ) }</td>
					<td>${ escHtml( reasonLabel( item.reason ) ) }</td>
					<td><a href="${ escHtml(
						editUrl
					) }" target="_blank" rel="noopener noreferrer">${ escHtml(
						i18n.edit
					) }</a></td>
				</tr>` );
		} );

		if ( rows.length ) {
			table.style.display = '';
			body.innerHTML = rows.join( '' );
		} else {
			table.style.display = 'none';
			body.innerHTML = '';
		}
	}

	function buildEnqueueItems( result ) {
		const items = [];
		const posts = Array.isArray( result.posts ) ? result.posts : [];

		posts.forEach( ( post ) => {
			if ( ! post.id ) {
				return;
			}
			items.push( {
				post_id: post.id,
				heading_hash: 'featured',
				heading_text: post.title || '',
			} );
		} );

		return items;
	}

	function renderProgress( done, total, statusText ) {
		const progress = q( '#sim-generate-progress' );
		const fill = q( '#sim-generate-progress-fill' );
		const status = q( '#sim-generate-progress-status' );

		if ( ! progress || ! fill || ! status ) {
			return;
		}

		const percentLabel = q( '#sim-generate-progress-percent' );
		const percent =
			total > 0
				? Math.min( 100, Math.round( ( done / total ) * 100 ) )
				: 0;
		progress.style.display = 'block';
		fill.style.width = `${ percent }%`;
		if ( percentLabel ) {
			percentLabel.textContent = `${ percent }%`;
		}
		status.textContent =
			statusText || sprintf( i18n.progress, done, total );
	}

	function stopPolling() {
		if ( pollTimer ) {
			window.clearTimeout( pollTimer );
			pollTimer = null;
		}
	}

	async function pollJobs() {
		if ( ! activeJobs.length ) {
			return;
		}

		const stillActive = [];

		for ( const job of activeJobs ) {
			try {
				const status = await apiFetch( {
					path: `/smart-image-matcher/v1/generate-image/status?post_id=${
						job.post_id
					}&heading_hash=${ encodeURIComponent( job.heading_hash ) }`,
					method: 'GET',
				} );

				const state = status.status || 'processing';
				if (
					'done' === state ||
					'completed' === state ||
					'exists' === state
				) {
					++succeededCount;
				} else if ( 'failed' === state || 'error' === state ) {
					++failedCount;
				} else {
					stillActive.push( job );
				}
			} catch ( err ) {
				stillActive.push( job );
			}
		}

		activeJobs = stillActive;
		const finished = succeededCount + failedCount;
		renderProgress(
			finished,
			pollTotal,
			sprintf( i18n.progress, finished, pollTotal )
		);

		if ( ! activeJobs.length ) {
			setWorking( false );
			const msg =
				failedCount > 0
					? `${
							i18n.generateComplete
					  } (${ failedCount } ${ i18n.failed.toLowerCase() })`
					: i18n.generateComplete;
			showNotice( failedCount > 0 ? 'warning' : 'success', msg );
			return;
		}

		pollTimer = window.setTimeout( pollJobs, 3000 );
	}

	async function scanPosts() {
		const form = collectFormData();

		if ( ! form.post_statuses.length ) {
			showNotice( 'error', i18n.noStatuses );
			return;
		}

		clearNotice();
		stopPolling();
		scanResult = null;
		setWorking( true );
		showNotice( 'info', i18n.scanning );

		try {
			const result = await apiFetch( {
				path: '/smart-image-matcher/v1/generate-images/scan',
				method: 'POST',
				data: form,
			} );

			scanResult = result;
			renderScanResults( result );

			const total = parseInt( result.total_images || 0, 10 );
			const skippedCount = Array.isArray( result.skipped )
				? result.skipped.length
				: 0;
			if ( total <= 0 ) {
				showNotice(
					'warning',
					skippedCount > 0
						? `${ i18n.noResults } (${ skippedCount } skipped — see table).`
						: i18n.noResults
				);
			} else {
				showNotice( 'success', i18n.scanComplete );
			}
		} catch ( err ) {
			showNotice( 'error', err.message || i18n.scanFailed );
		} finally {
			setWorking( false );
		}
	}

	async function generateAll() {
		if ( ! scanResult || ! generationReady ) {
			return;
		}

		const total = parseInt( scanResult.total_images || 0, 10 );
		if ( total <= 0 ) {
			return;
		}

		const confirmed = window.confirm(
			sprintf( i18n.confirmGenerate, total )
		);
		if ( ! confirmed ) {
			return;
		}

		const form = collectFormData();
		const items = buildEnqueueItems( scanResult );

		clearNotice();
		stopPolling();
		setWorking( true );
		showNotice( 'info', i18n.generating );

		try {
			const result = await apiFetch( {
				path: '/smart-image-matcher/v1/generate-images/enqueue',
				method: 'POST',
				data: {
					items,
					style: form.style,
				},
			} );

			activeJobs = Array.isArray( result.jobs ) ? result.jobs : [];
			const queued = parseInt( result.queued || 0, 10 );
			const skipped = parseInt( result.skipped || 0, 10 );
			pollTotal = activeJobs.length;
			succeededCount = 0;
			failedCount = 0;

			if ( queued <= 0 ) {
				showNotice( 'warning', i18n.noResults );
				setWorking( false );
				return;
			}

			renderProgress(
				0,
				pollTotal,
				sprintf( i18n.progress, 0, pollTotal )
			);
			showNotice(
				'info',
				`${ i18n.queued }: ${ queued }${
					skipped > 0 ? ` (${ skipped } skipped)` : ''
				}`
			);
			pollJobs();
		} catch ( err ) {
			setWorking( false );
			showNotice( 'error', err.message || i18n.generateFailed );
		}
	}

	function boot() {
		if ( ! q( '#sim-generate-images-form' ) ) {
			return;
		}

		if ( ! apiFetch ) {
			showNotice( 'error', i18n.noApi );
			return;
		}

		const scanBtn = q( '#sim-generate-scan-button' );
		const genBtn = q( '#sim-generate-all-button' );
		const recoveryPreviewBtn = q( '#sim-fal-recovery-preview-button' );
		const recoveryRunBtn = q( '#sim-fal-recovery-run-button' );

		if ( scanBtn ) {
			scanBtn.addEventListener( 'click', scanPosts );
		}
		if ( genBtn ) {
			genBtn.addEventListener( 'click', generateAll );
		}
		if ( recoveryPreviewBtn ) {
			recoveryPreviewBtn.addEventListener( 'click', previewRecovery );
		}
		if ( recoveryRunBtn ) {
			recoveryRunBtn.addEventListener( 'click', runRecovery );
		}

		if ( focusAi || prefillPostIds.length ) {
			const card = q( '#sim-featured-ai' );
			if ( card && card.scrollIntoView ) {
				card.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}
		}

		if ( prefillPostIds.length ) {
			scanPosts();
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
