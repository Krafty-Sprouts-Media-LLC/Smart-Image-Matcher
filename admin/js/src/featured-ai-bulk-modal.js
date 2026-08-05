/**
 * featured-ai-bulk-modal.js — Posts list dismissable modal for featured AI generate.
 *
 * @package SmartImageMatcher
 * @since   3.2.3
 */

( function () {
	'use strict';

	const config = window.smartImageMatcherFeaturedAiBulk || {};
	const apiFetch = window.wp && window.wp.apiFetch;
	const nonce = config.nonce || '';
	const postIds = Array.isArray( config.postIds ) ? config.postIds.map( Number ).filter( Boolean ) : [];
	const postType = config.postType || 'post';
	const autoOpen = !! config.autoOpen;
	const generationReady = !! config.generationReady;
	const defaultStyle = config.defaultStyle === 'illustration' ? 'illustration' : 'photo';
	const editPostUrl = config.editPostUrl || 'post.php?post=%d&action=edit';

	const i18n = Object.assign( {
		title: 'Generate featured images',
		scanning: 'Scanning selected posts…',
		scanFailed: 'Could not scan posts.',
		noResults: 'None of the selected posts need a featured image.',
		confirmGenerate: 'Generate %d featured image(s)? Time varies by model — often a few minutes each.',
		generating: 'Queueing jobs…',
		generateFailed: 'Could not queue jobs.',
		queuedNotice: '%d featured image job(s) queued. You can dismiss this dialog; generation continues in the background.',
		allDone: 'Finished: %1$d succeeded, %2$d failed. Refresh the posts list to see new featured images.',
		allDoneOk: 'Finished: %d featured image(s) set. Refresh the posts list to see them.',
		estimateHint: 'Usually a few minutes per image (varies by model and queue).',
		imagesCount: '%d to generate',
		estimateWithCount: '%1$d to generate. %2$s',
		dismiss: 'Dismiss',
		generate: 'Generate',
		scan: 'Scan',
		close: 'Close',
		needsFeatured: 'Missing featured image',
		alreadyHasFeatured: 'Already has featured image',
		noThumbnailSupport: 'No featured image support',
		alreadyGenerated: 'Already generated',
		rejected: 'Previously rejected',
		notFound: 'Not found',
		noPermission: 'No permission',
		skippedOther: 'Skipped',
		queued: 'Queued…',
		processing: 'Generating…',
		generated: 'Featured image set',
		failed: 'Failed',
		edit: 'Edit',
		minute: 'minute',
		minutes: 'minutes',
		second: 'second',
		seconds: 'seconds',
		progress: 'Completed %1$d of %2$d',
		styleLabel: 'Style',
		stylePhoto: 'Photo (realistic)',
		styleIllustration: 'Illustration',
		noApi: 'Could not load controls (wp.apiFetch missing).',
		notReady: 'Enable on-demand image generation and connect an image provider first.',
	}, config.i18n || {} );

	if ( apiFetch && apiFetch.createNonceMiddleware && nonce ) {
		apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );
	}

	let modal = null;
	let scanResult = null;
	let activeJobs = [];
	let pollTotal = 0;
	let succeededCount = 0;
	let failedCount = 0;
	let pollTimer = null;
	let dismissed = false;
	const handledStorageKey = 'sim_featured_ai_handled_' + postIds.slice().sort( ( a, b ) => a - b ).join( ',' );

	function wasBatchHandled() {
		try {
			return '1' === window.sessionStorage.getItem( handledStorageKey );
		} catch ( e ) {
			return false;
		}
	}

	function markBatchHandled() {
		try {
			window.sessionStorage.setItem( handledStorageKey, '1' );
		} catch ( e ) {
			// Ignore quota / private mode.
		}
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
				return null === value || undefined === value ? '' : String( value );
			} )
			.replace( /%[ds]/g, () => {
				const value = args[ i++ ];
				return null === value || undefined === value ? '' : String( value );
			} );
	}

	function formatDuration( seconds ) {
		const total = Math.max( 0, parseInt( seconds || 0, 10 ) );
		if ( total < 60 ) {
			return `${ total } ${ total === 1 ? i18n.second : i18n.seconds }`;
		}
		const mins = Math.round( total / 60 );
		return `${ mins } ${ mins === 1 ? i18n.minute : i18n.minutes }`;
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

	/**
	 * Primary only when Generate is actually clickable; Scan leads until then.
	 *
	 * @param {HTMLButtonElement|null} genBtn
	 * @param {boolean} enabled
	 */
	function setGenerateReady( genBtn, enabled ) {
		if ( ! genBtn ) {
			return;
		}
		genBtn.disabled = ! enabled;
		genBtn.setAttribute( 'aria-disabled', enabled ? 'false' : 'true' );
		genBtn.classList.toggle( 'button-primary', enabled );
		const scanBtn = modal && modal.querySelector( '#sim-featured-ai-scan' );
		if ( scanBtn && ! scanBtn.disabled ) {
			scanBtn.classList.toggle( 'button-primary', ! enabled );
		}
	}

	function isTerminalSuccess( state ) {
		return 'done' === state || 'completed' === state || 'exists' === state;
	}

	function isTerminalFailure( state ) {
		return 'failed' === state || 'error' === state;
	}

	function cleanUrl() {
		try {
			const url = new URL( window.location.href );
			url.searchParams.delete( 'sim_featured_ai' );
			url.searchParams.delete( 'sim_featured_ids' );
			window.history.replaceState( {}, document.title, url.toString() );
		} catch ( e ) {
			// Ignore.
		}
	}

	function setRowStatus( postId, label ) {
		if ( ! modal ) {
			return;
		}
		const cell = modal.querySelector( `[data-sim-post-status="${ postId }"]` );
		if ( cell ) {
			cell.textContent = label;
		}
	}

	function ensureModal() {
		if ( modal ) {
			return modal;
		}

		modal = document.createElement( 'div' );
		modal.id = 'sim-featured-ai-modal';
		modal.className = 'sim-featured-ai-modal';
		modal.setAttribute( 'role', 'dialog' );
		modal.setAttribute( 'aria-modal', 'true' );
		modal.setAttribute( 'aria-labelledby', 'sim-featured-ai-modal-title' );
		modal.innerHTML = `
			<div class="sim-featured-ai-modal__overlay" data-sim-dismiss="1"></div>
			<div class="sim-featured-ai-modal__panel">
				<div class="sim-featured-ai-modal__header">
					<h2 id="sim-featured-ai-modal-title">${ escHtml( i18n.title ) }</h2>
					<button type="button" class="sim-featured-ai-modal__close" data-sim-dismiss="1" aria-label="${ escHtml( i18n.close ) }">&times;</button>
				</div>
				<div class="sim-featured-ai-modal__body">
					<div class="sim-featured-ai-modal__toolbar">
						<label for="sim-featured-ai-style">${ escHtml( i18n.styleLabel ) }
							<select id="sim-featured-ai-style">
								<option value="photo">${ escHtml( i18n.stylePhoto ) }</option>
								<option value="illustration">${ escHtml( i18n.styleIllustration ) }</option>
							</select>
						</label>
						<button type="button" class="button button-primary" id="sim-featured-ai-scan">${ escHtml( i18n.scan ) }</button>
						<button type="button" class="button" id="sim-featured-ai-generate" disabled aria-disabled="true">${ escHtml( i18n.generate ) }</button>
					</div>
					<p id="sim-featured-ai-estimate" class="description sim-featured-ai-modal__estimate" style="display:none"></p>
					<div id="sim-featured-ai-notice" aria-live="polite"></div>
					<table class="widefat striped" id="sim-featured-ai-table" style="display:none">
						<thead>
							<tr>
								<th>${ escHtml( 'Post' ) }</th>
								<th>${ escHtml( 'Status' ) }</th>
								<th>${ escHtml( i18n.edit ) }</th>
							</tr>
						</thead>
						<tbody id="sim-featured-ai-tbody"></tbody>
					</table>
					<p id="sim-featured-ai-progress" class="description" style="display:none"></p>
				</div>
				<div class="sim-featured-ai-modal__footer">
					<button type="button" class="button" data-sim-dismiss="1">${ escHtml( i18n.dismiss ) }</button>
				</div>
			</div>`;

		document.body.appendChild( modal );

		const styleSelect = modal.querySelector( '#sim-featured-ai-style' );
		if ( styleSelect ) {
			styleSelect.value = defaultStyle;
		}

		modal.addEventListener( 'click', ( e ) => {
			if ( e.target && e.target.getAttribute( 'data-sim-dismiss' ) ) {
				closeModal();
			}
		} );

		const scanBtn = modal.querySelector( '#sim-featured-ai-scan' );
		const genBtn = modal.querySelector( '#sim-featured-ai-generate' );
		if ( scanBtn ) {
			scanBtn.addEventListener( 'click', runScan );
		}
		if ( genBtn ) {
			genBtn.addEventListener( 'click', runGenerate );
		}

		return modal;
	}

	function showNotice( type, message ) {
		const el = modal && modal.querySelector( '#sim-featured-ai-notice' );
		if ( ! el ) {
			return;
		}
		el.innerHTML = message
			? `<div class="notice notice-${ escHtml( type ) } inline"><p>${ escHtml( message ) }</p></div>`
			: '';
	}

	function openModal() {
		dismissed = false;
		ensureModal();
		modal.style.display = 'flex';
		document.body.classList.add( 'sim-featured-ai-modal-open' );
		cleanUrl();
		if ( generationReady && postIds.length ) {
			runScan();
		} else if ( ! generationReady ) {
			showNotice( 'warning', i18n.notReady );
		}
	}

	function closeModal() {
		dismissed = true;
		markBatchHandled();
		cleanUrl();
		if ( modal ) {
			modal.style.display = 'none';
		}
		document.body.classList.remove( 'sim-featured-ai-modal-open' );
	}

	function renderResults( result ) {
		const table = modal.querySelector( '#sim-featured-ai-table' );
		const tbody = modal.querySelector( '#sim-featured-ai-tbody' );
		const estimate = modal.querySelector( '#sim-featured-ai-estimate' );
		const genBtn = modal.querySelector( '#sim-featured-ai-generate' );

		const posts = Array.isArray( result.posts ) ? result.posts : [];
		const skipped = Array.isArray( result.skipped ) ? result.skipped : [];
		const total = parseInt( result.total_images || 0, 10 );

		if ( estimate ) {
			if ( total > 0 ) {
				estimate.style.display = 'block';
				estimate.textContent = sprintf(
					i18n.estimateWithCount,
					total,
					result.estimate_hint || i18n.estimateHint
				);
			} else {
				estimate.style.display = 'none';
				estimate.textContent = '';
			}
		}

		const rows = [];
		posts.forEach( post => {
			const editUrl = editPostUrl.replace( '%d', String( post.id ) );
			rows.push( `<tr data-sim-post-id="${ escHtml( String( post.id ) ) }"><td>${ escHtml( post.title || '#' + post.id ) }</td><td data-sim-post-status="${ escHtml( String( post.id ) ) }">${ escHtml( i18n.needsFeatured ) }</td><td><a href="${ escHtml( editUrl ) }" target="_blank" rel="noopener noreferrer">${ escHtml( i18n.edit ) }</a></td></tr>` );
		} );
		skipped.forEach( item => {
			const editUrl = editPostUrl.replace( '%d', String( item.id ) );
			rows.push( `<tr><td>${ escHtml( item.title || '#' + item.id ) }</td><td>${ escHtml( reasonLabel( item.reason ) ) }</td><td><a href="${ escHtml( editUrl ) }" target="_blank" rel="noopener noreferrer">${ escHtml( i18n.edit ) }</a></td></tr>` );
		} );

		if ( table && tbody ) {
			tbody.innerHTML = rows.join( '' );
			table.style.display = rows.length ? '' : 'none';
		}

		if ( genBtn ) {
			setGenerateReady( genBtn, generationReady && total > 0 );
		}
	}

	async function runScan() {
		if ( ! apiFetch || ! postIds.length ) {
			showNotice( 'error', i18n.noApi );
			return;
		}

		const styleEl = modal.querySelector( '#sim-featured-ai-style' );
		const style = styleEl ? styleEl.value : defaultStyle;

		showNotice( 'info', i18n.scanning );
		scanResult = null;
		setGenerateReady( modal.querySelector( '#sim-featured-ai-generate' ), false );

		try {
			const result = await apiFetch( {
				path: '/smart-image-matcher/v1/generate-images/scan',
				method: 'POST',
				data: {
					post_type: postType,
					post_ids: postIds,
					post_statuses: [ 'publish', 'draft', 'pending', 'private', 'future' ],
					style,
					max_posts: postIds.length,
				},
			} );
			scanResult = result;
			renderResults( result );
			const total = parseInt( result.total_images || 0, 10 );
			if ( total > 0 ) {
				showNotice( 'success', '' );
			} else {
				showNotice( 'warning', i18n.noResults );
			}
		} catch ( err ) {
			showNotice( 'error', err.message || i18n.scanFailed );
		}
	}

	function updateProgress() {
		const finished = succeededCount + failedCount;
		const progress = modal && modal.querySelector( '#sim-featured-ai-progress' );
		if ( progress && ! dismissed ) {
			progress.style.display = 'block';
			progress.textContent = sprintf( i18n.progress, finished, pollTotal );
		}
	}

	async function pollJobs() {
		if ( ! activeJobs.length ) {
			return;
		}

		const still = [];

		for ( const job of activeJobs ) {
			try {
				const status = await apiFetch( {
					path: `/smart-image-matcher/v1/generate-image/status?post_id=${ job.post_id }&heading_hash=${ encodeURIComponent( job.heading_hash ) }`,
					method: 'GET',
				} );
				const state = status.status || 'processing';

				if ( isTerminalSuccess( state ) ) {
					++succeededCount;
					setRowStatus( job.post_id, i18n.generated );
				} else if ( isTerminalFailure( state ) ) {
					++failedCount;
					const err = status.error ? `${ i18n.failed }: ${ status.error }` : i18n.failed;
					setRowStatus( job.post_id, err );
				} else {
					if ( 'processing' === state ) {
						setRowStatus( job.post_id, i18n.processing );
					}
					still.push( job );
				}
			} catch ( e ) {
				still.push( job );
			}
		}

		activeJobs = still;
		updateProgress();

		if ( ! activeJobs.length ) {
			if ( dismissed ) {
				return;
			}
			if ( failedCount > 0 ) {
				showNotice( 'warning', sprintf( i18n.allDone, succeededCount, failedCount ) );
			} else {
				showNotice( 'success', sprintf( i18n.allDoneOk, succeededCount ) );
			}
			return;
		}

		pollTimer = window.setTimeout( pollJobs, 3000 );
	}

	async function runGenerate() {
		if ( ! scanResult || ! generationReady ) {
			return;
		}

		const total = parseInt( scanResult.total_images || 0, 10 );
		if ( total <= 0 ) {
			return;
		}

		if ( ! window.confirm( sprintf( i18n.confirmGenerate, total ) ) ) {
			return;
		}

		const styleEl = modal.querySelector( '#sim-featured-ai-style' );
		const style = styleEl ? styleEl.value : defaultStyle;
		const items = ( scanResult.posts || [] ).map( post => ( {
			post_id: post.id,
			heading_hash: 'featured',
			heading_text: post.title || '',
		} ) );

		showNotice( 'info', i18n.generating );
		setGenerateReady( modal.querySelector( '#sim-featured-ai-generate' ), false );

		try {
			const result = await apiFetch( {
				path: '/smart-image-matcher/v1/generate-images/enqueue',
				method: 'POST',
				data: { items, style },
			} );

			const queued = parseInt( result.queued || 0, 10 );
			activeJobs = Array.isArray( result.jobs ) ? result.jobs : [];
			pollTotal = activeJobs.length;
			succeededCount = 0;
			failedCount = 0;

			activeJobs.forEach( job => setRowStatus( job.post_id, i18n.queued ) );

			showNotice( 'success', sprintf( i18n.queuedNotice, queued ) );
			updateProgress();
			markBatchHandled();
			cleanUrl();

			if ( activeJobs.length ) {
				pollJobs();
			}
		} catch ( err ) {
			showNotice( 'error', err.message || i18n.generateFailed );
			setGenerateReady(
				modal.querySelector( '#sim-featured-ai-generate' ),
				generationReady && parseInt( scanResult.total_images || 0, 10 ) > 0
			);
		}
	}

	function boot() {
		if ( ! autoOpen || ! postIds.length ) {
			return;
		}
		// Pagination links can still carry one-shot args after replaceState; skip if already handled.
		if ( wasBatchHandled() ) {
			cleanUrl();
			return;
		}
		if ( ! apiFetch ) {
			window.alert( i18n.noApi );
			return;
		}
		openModal();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
