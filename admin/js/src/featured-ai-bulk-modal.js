/**
 * featured-ai-bulk-modal.js — Posts list dismissable modal for featured AI generate.
 *
 * After dismiss, a sticky dock keeps per-post progress visible and resumes after
 * pagination via sessionStorage.
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
	const BATCH_STORAGE_KEY = 'sim_featured_ai_batch';

	const i18n = Object.assign( {
		title: 'Generate featured images',
		scanning: 'Scanning selected posts…',
		scanFailed: 'Could not scan posts.',
		noResults: 'None of the selected posts need a featured image.',
		confirmGenerate: 'Generate %d featured image(s)? Time varies by model — often a few minutes each.',
		generating: 'Queueing jobs…',
		generateFailed: 'Could not queue jobs.',
		queuedNotice: '%d featured image job(s) queued. You can dismiss this dialog; progress stays visible below.',
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
		dockTitle: 'Featured image generation',
		dockRemaining: '%d remaining',
		dockExpand: 'Show details',
		dockCollapse: 'Hide details',
		dockReopen: 'Open dialog',
		dockHide: 'Hide',
		untitled: 'Untitled',
	}, config.i18n || {} );

	if ( apiFetch && apiFetch.createNonceMiddleware && nonce ) {
		apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );
	}

	let modal = null;
	let dock = null;
	let scanResult = null;
	let activeJobs = [];
	let jobMeta = {};
	let pollTotal = 0;
	let succeededCount = 0;
	let failedCount = 0;
	let pollTimer = null;
	let dismissed = false;
	let dockExpanded = true;
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

	function postTitle( postId ) {
		const meta = jobMeta[ String( postId ) ];
		if ( meta && meta.title ) {
			return meta.title;
		}
		return i18n.untitled + ' #' + postId;
	}

	function setJobStatus( postId, label ) {
		const key = String( postId );
		if ( ! jobMeta[ key ] ) {
			jobMeta[ key ] = { title: postTitle( postId ), status: label };
		} else {
			jobMeta[ key ].status = label;
		}
		setRowStatus( postId, label );
		renderDock();
		persistBatch();
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

	function persistBatch() {
		try {
			const jobs = [];
			Object.keys( jobMeta ).forEach( ( id ) => {
				const meta = jobMeta[ id ];
				const stillActive = activeJobs.some( ( j ) => String( j.post_id ) === id );
				jobs.push( {
					post_id: parseInt( id, 10 ),
					heading_hash: 'featured',
					title: meta.title || '',
					status: meta.status || i18n.queued,
					active: stillActive,
				} );
			} );

			if ( ! jobs.length ) {
				window.sessionStorage.removeItem( BATCH_STORAGE_KEY );
				return;
			}

			window.sessionStorage.setItem(
				BATCH_STORAGE_KEY,
				JSON.stringify( {
					jobs,
					pollTotal,
					succeededCount,
					failedCount,
					dockExpanded,
					updatedAt: Date.now(),
				} )
			);
		} catch ( e ) {
			// Ignore.
		}
	}

	function clearBatchStorage() {
		try {
			window.sessionStorage.removeItem( BATCH_STORAGE_KEY );
		} catch ( e ) {
			// Ignore.
		}
	}

	function loadStoredBatch() {
		try {
			const raw = window.sessionStorage.getItem( BATCH_STORAGE_KEY );
			if ( ! raw ) {
				return null;
			}
			const data = JSON.parse( raw );
			if ( ! data || ! Array.isArray( data.jobs ) || ! data.jobs.length ) {
				return null;
			}
			// Stale after 2 hours.
			if ( data.updatedAt && ( Date.now() - data.updatedAt ) > 2 * 60 * 60 * 1000 ) {
				clearBatchStorage();
				return null;
			}
			return data;
		} catch ( e ) {
			return null;
		}
	}

	function ensureDock() {
		if ( dock ) {
			return dock;
		}

		dock = document.createElement( 'div' );
		dock.id = 'sim-featured-ai-dock';
		dock.className = 'sim-featured-ai-dock';
		dock.setAttribute( 'role', 'status' );
		dock.setAttribute( 'aria-live', 'polite' );
		dock.innerHTML = `
			<div class="sim-featured-ai-dock__header">
				<strong class="sim-featured-ai-dock__title">${ escHtml( i18n.dockTitle ) }</strong>
				<div class="sim-featured-ai-dock__actions">
					<button type="button" class="button-link" id="sim-featured-ai-dock-toggle">${ escHtml( i18n.dockCollapse ) }</button>
					<button type="button" class="button-link" id="sim-featured-ai-dock-reopen">${ escHtml( i18n.dockReopen ) }</button>
					<button type="button" class="button-link" id="sim-featured-ai-dock-hide">${ escHtml( i18n.dockHide ) }</button>
				</div>
			</div>
			<p class="sim-featured-ai-dock__summary" id="sim-featured-ai-dock-summary"></p>
			<ul class="sim-featured-ai-dock__list" id="sim-featured-ai-dock-list"></ul>
		`;
		document.body.appendChild( dock );

		const toggle = dock.querySelector( '#sim-featured-ai-dock-toggle' );
		const reopen = dock.querySelector( '#sim-featured-ai-dock-reopen' );
		const hide = dock.querySelector( '#sim-featured-ai-dock-hide' );

		if ( toggle ) {
			toggle.addEventListener( 'click', () => {
				dockExpanded = ! dockExpanded;
				renderDock();
				persistBatch();
			} );
		}
		if ( reopen ) {
			reopen.addEventListener( 'click', () => {
				openModal( false );
			} );
		}
		if ( hide ) {
			hide.addEventListener( 'click', () => {
				if ( activeJobs.length ) {
					dock.style.display = 'none';
					return;
				}
				hideDock( true );
			} );
		}

		return dock;
	}

	function hideDock( clearStorage ) {
		if ( dock ) {
			dock.style.display = 'none';
		}
		if ( clearStorage ) {
			clearBatchStorage();
		}
	}

	function showDock() {
		ensureDock();
		dock.style.display = 'block';
		renderDock();
	}

	function renderDock() {
		if ( ! dock || dock.style.display === 'none' ) {
			return;
		}

		const finished = succeededCount + failedCount;
		const remaining = activeJobs.length;
		const summary = dock.querySelector( '#sim-featured-ai-dock-summary' );
		const list = dock.querySelector( '#sim-featured-ai-dock-list' );
		const toggle = dock.querySelector( '#sim-featured-ai-dock-toggle' );
		const reopen = dock.querySelector( '#sim-featured-ai-dock-reopen' );

		if ( summary ) {
			let text = sprintf( i18n.progress, finished, pollTotal || finished );
			if ( remaining > 0 ) {
				text += ' — ' + sprintf( i18n.dockRemaining, remaining );
			} else if ( failedCount > 0 ) {
				text = sprintf( i18n.allDone, succeededCount, failedCount );
			} else if ( finished > 0 ) {
				text = sprintf( i18n.allDoneOk, succeededCount );
			}
			summary.textContent = text;
		}

		if ( toggle ) {
			toggle.textContent = dockExpanded ? i18n.dockCollapse : i18n.dockExpand;
		}
		if ( reopen ) {
			reopen.style.display = dismissed ? '' : 'none';
		}

		if ( list ) {
			list.style.display = dockExpanded ? '' : 'none';
			const ids = Object.keys( jobMeta ).sort( ( a, b ) => parseInt( a, 10 ) - parseInt( b, 10 ) );
			list.innerHTML = ids.map( ( id ) => {
				const meta = jobMeta[ id ];
				const editUrl = editPostUrl.replace( '%d', id );
				const status = meta.status || i18n.queued;
				let statusClass = 'is-pending';
				if ( status === i18n.generated ) {
					statusClass = 'is-done';
				} else if ( 0 === status.indexOf( i18n.failed ) ) {
					statusClass = 'is-failed';
				}
				return `<li class="sim-featured-ai-dock__item ${ statusClass }"><span class="sim-featured-ai-dock__item-title">${ escHtml( meta.title || ( '#' + id ) ) }</span><span class="sim-featured-ai-dock__item-status">${ escHtml( status ) }</span><a class="sim-featured-ai-dock__item-edit" href="${ escHtml( editUrl ) }" target="_blank" rel="noopener noreferrer">${ escHtml( i18n.edit ) }</a></li>`;
			} ).join( '' );
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
								<th>${ escHtml( i18n.title ) }</th>
								<th>${ escHtml( i18n.processing ) }</th>
								<th></th>
							</tr>
						</thead>
						<tbody id="sim-featured-ai-tbody"></tbody>
					</table>
					<p id="sim-featured-ai-progress" class="description" style="display:none"></p>
				</div>
				<div class="sim-featured-ai-modal__footer">
					<button type="button" class="button" data-sim-dismiss="1">${ escHtml( i18n.dismiss ) }</button>
				</div>
			</div>
		`;
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

	/**
	 * @param {boolean} runInitialScan Whether to scan selected posts (URL auto-open).
	 */
	function openModal( runInitialScan ) {
		dismissed = false;
		ensureModal();
		syncModalTableFromMeta();
		modal.style.display = 'flex';
		document.body.classList.add( 'sim-featured-ai-modal-open' );
		updateProgress();

		if ( false === runInitialScan ) {
			return;
		}

		cleanUrl();
		if ( generationReady && postIds.length ) {
			runScan();
		} else if ( ! generationReady ) {
			showNotice( 'warning', i18n.notReady );
		}
	}

	function syncModalTableFromMeta() {
		if ( ! modal || ! Object.keys( jobMeta ).length ) {
			return;
		}

		const table = modal.querySelector( '#sim-featured-ai-table' );
		const tbody = modal.querySelector( '#sim-featured-ai-tbody' );
		if ( ! table || ! tbody ) {
			return;
		}

		const rows = Object.keys( jobMeta )
			.sort( ( a, b ) => parseInt( a, 10 ) - parseInt( b, 10 ) )
			.map( ( id ) => {
				const meta = jobMeta[ id ];
				const editUrl = editPostUrl.replace( '%d', id );
				return `<tr data-sim-post-id="${ escHtml( id ) }"><td>${ escHtml( meta.title || ( '#' + id ) ) }</td><td data-sim-post-status="${ escHtml( id ) }">${ escHtml( meta.status || i18n.queued ) }</td><td><a href="${ escHtml( editUrl ) }" target="_blank" rel="noopener noreferrer">${ escHtml( i18n.edit ) }</a></td></tr>`;
			} );

		tbody.innerHTML = rows.join( '' );
		table.style.display = rows.length ? '' : 'none';
	}

	function closeModal() {
		dismissed = true;
		markBatchHandled();
		cleanUrl();
		if ( modal ) {
			modal.style.display = 'none';
		}
		document.body.classList.remove( 'sim-featured-ai-modal-open' );

		if ( Object.keys( jobMeta ).length ) {
			showDock();
			persistBatch();
		}
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
		renderDock();
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
					setJobStatus( job.post_id, i18n.generated );
				} else if ( isTerminalFailure( state ) ) {
					++failedCount;
					const err = status.error ? `${ i18n.failed }: ${ status.error }` : i18n.failed;
					setJobStatus( job.post_id, err );
				} else {
					if ( 'processing' === state || 'submitted' === state || 'queued' === state ) {
						setJobStatus( job.post_id, i18n.processing );
					}
					still.push( job );
				}
			} catch ( e ) {
				still.push( job );
			}
		}

		activeJobs = still;
		updateProgress();
		persistBatch();

		if ( ! activeJobs.length ) {
			persistBatch();
			if ( ! dismissed ) {
				if ( failedCount > 0 ) {
					showNotice( 'warning', sprintf( i18n.allDone, succeededCount, failedCount ) );
				} else {
					showNotice( 'success', sprintf( i18n.allDoneOk, succeededCount ) );
				}
			} else {
				showDock();
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
			jobMeta = {};

			( scanResult.posts || [] ).forEach( ( post ) => {
				jobMeta[ String( post.id ) ] = {
					title: post.title || ( '#' + post.id ),
					status: i18n.queued,
				};
			} );

			activeJobs.forEach( job => setJobStatus( job.post_id, i18n.queued ) );

			showNotice( 'success', sprintf( i18n.queuedNotice, queued ) );
			updateProgress();
			markBatchHandled();
			cleanUrl();
			persistBatch();

			if ( activeJobs.length ) {
				if ( pollTimer ) {
					window.clearTimeout( pollTimer );
				}
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

	function resumeStoredBatch() {
		const data = loadStoredBatch();
		if ( ! data || ! apiFetch ) {
			return false;
		}

		jobMeta = {};
		activeJobs = [];
		pollTotal = parseInt( data.pollTotal || data.jobs.length, 10 ) || data.jobs.length;
		succeededCount = parseInt( data.succeededCount || 0, 10 );
		failedCount = parseInt( data.failedCount || 0, 10 );
		dockExpanded = false !== data.dockExpanded;

		data.jobs.forEach( ( job ) => {
			const id = String( job.post_id );
			jobMeta[ id ] = {
				title: job.title || ( '#' + job.post_id ),
				status: job.status || i18n.queued,
			};
			const st = jobMeta[ id ].status;
			const terminal = st === i18n.generated || 0 === st.indexOf( i18n.failed );
			if ( ! terminal ) {
				activeJobs.push( {
					post_id: job.post_id,
					heading_hash: job.heading_hash || 'featured',
				} );
			}
		} );

		// Re-count terminals from stored statuses if counters look stale.
		let ok = 0;
		let bad = 0;
		Object.keys( jobMeta ).forEach( ( id ) => {
			const st = jobMeta[ id ].status || '';
			if ( st === i18n.generated ) {
				++ok;
			} else if ( st.indexOf( i18n.failed ) === 0 ) {
				++bad;
			}
		} );
		if ( ok + bad > succeededCount + failedCount ) {
			succeededCount = ok;
			failedCount = bad;
		}

		dismissed = true;
		showDock();

		if ( activeJobs.length ) {
			if ( pollTimer ) {
				window.clearTimeout( pollTimer );
			}
			pollJobs();
		}

		return true;
	}

	function labelForServerStatus( state ) {
		if ( 'queued' === state ) {
			return i18n.queued;
		}
		if ( 'failed' === state || 'error' === state ) {
			return i18n.failed;
		}
		if ( isTerminalSuccess( state ) ) {
			return i18n.generated;
		}
		return i18n.processing;
	}

	/**
	 * Resume dock from Action Scheduler when sessionStorage is empty
	 * (e.g. modal was closed before the dock feature shipped).
	 *
	 * @return {Promise<boolean>}
	 */
	async function resumeServerBatch() {
		if ( ! apiFetch ) {
			return false;
		}

		try {
			const result = await apiFetch( {
				path: '/smart-image-matcher/v1/generate-images/active',
				method: 'GET',
			} );
			const jobs = Array.isArray( result.jobs ) ? result.jobs : [];
			if ( ! jobs.length ) {
				return false;
			}

			jobMeta = {};
			activeJobs = [];
			succeededCount = 0;
			failedCount = 0;
			pollTotal = jobs.length;
			dockExpanded = true;

			jobs.forEach( ( job ) => {
				const id = String( job.post_id );
				jobMeta[ id ] = {
					title: job.title || ( '#' + job.post_id ),
					status: labelForServerStatus( job.status || 'processing' ),
				};
				activeJobs.push( {
					post_id: job.post_id,
					heading_hash: job.heading_hash || 'featured',
				} );
			} );

			dismissed = true;
			showDock();
			persistBatch();

			if ( pollTimer ) {
				window.clearTimeout( pollTimer );
			}
			pollJobs();
			return true;
		} catch ( e ) {
			return false;
		}
	}

	async function boot() {
		if ( ! apiFetch ) {
			if ( autoOpen && postIds.length ) {
				window.alert( i18n.noApi );
			}
			return;
		}

		// Resume in-flight batch on any posts-list load (pagination / refresh).
		const resumedLocal = resumeStoredBatch();
		if ( ! resumedLocal ) {
			await resumeServerBatch();
		}

		if ( ! autoOpen || ! postIds.length ) {
			return;
		}

		if ( wasBatchHandled() ) {
			cleanUrl();
			return;
		}

		// Prefer not stacking a new modal on top of an already-running batch.
		if ( activeJobs.length ) {
			cleanUrl();
			return;
		}

		openModal( true );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
