/**
 * bulk.js — Bulk Processor SPA (Premium).
 *
 * Persistent Run | Review. Process articles or generate featured images.
 * Review is grouped by article. Run identity is a human label, never a job hash.
 *
 * @package SmartImageMatcher
 * @since   3.0.0
 */

( function () {
	'use strict';

	const config = window.smartImageMatcherBulk || {};
	const nonce = config.nonce || '';
	const postTypes = config.postTypes && Object.keys( config.postTypes ).length
		? config.postTypes
		: { post: 'Posts', page: 'Pages' };
	const autoInsert = parseInt( config.autoInsertThreshold || '90', 10 );
	const generationAvailable = !! config.generationAvailable;
	const i18n = Object.assign( {
		run: 'Run',
		review: 'Review',
		approve: 'Approve',
		reject: 'Reject',
		insertApproved: 'Insert approved',
		cancel: 'Cancel run',
		noMatches: 'Nothing to review.',
		allPending: 'All pending',
		lastRun: 'Last run',
	}, config.i18n || {} );
	const apiFetch = window.wp && window.wp.apiFetch;
	const samplePostRefs = config.samplePostRefs || '123, 456, sample-post-slug';
	const app = document.getElementById( 'sim-bulk-app' );

	if ( apiFetch && apiFetch.createNonceMiddleware && nonce ) {
		apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );
	}

	let currentTab = ( app && app.dataset.simTab === 'review' ) ? 'review' : 'run';
	let reviewRun = ( app && app.dataset.simRun === 'last' ) ? 'last' : 'all';
	let reviewSlot = 'all';
	let reviewPage = 1;
	let currentJob = null;
	let pollTimer = null;
	const storageKey = 'smartImageMatcherBulkCurrentJobId';
	const cancelledStorageKey = 'smartImageMatcherBulkCancelledJobIds';
	const segmentsKey = 'smartImageMatcherBulkSelectionSegments';

	function q( selector, root ) {
		return ( root || document ).querySelector( selector );
	}

	function qAll( selector, root ) {
		return Array.from( ( root || document ).querySelectorAll( selector ) );
	}

	function escHtml( str ) {
		const d = document.createElement( 'div' );
		d.textContent = str == null ? '' : String( str );
		return d.innerHTML;
	}

	function parseTotals( jobData ) {
		if ( jobData && typeof jobData.totals === 'string' && jobData.totals ) {
			try {
				const totals = JSON.parse( jobData.totals );
				return totals && typeof totals === 'object' ? totals : {};
			} catch ( err ) {
				return {};
			}
		}
		return ( jobData && jobData.totals && typeof jobData.totals === 'object' ) ? jobData.totals : {};
	}

	function getJobTotal( jobData ) {
		const totals = parseTotals( jobData );
		return parseInt( jobData.total || totals.total || 0, 10 );
	}

	function getJobDone( jobData ) {
		const totals = parseTotals( jobData );
		return parseInt( jobData.done || totals.done || 0, 10 );
	}

	function outcome( jobData, key ) {
		const totals = parseTotals( jobData );
		return parseInt( jobData[ key ] || totals[ key ] || 0, 10 );
	}

	function rememberJob( jobId ) {
		try { window.localStorage.setItem( storageKey, jobId ); } catch ( err ) {}
	}

	function forgetJob() {
		try { window.localStorage.removeItem( storageKey ); } catch ( err ) {}
	}

	function readCancelledJobs() {
		try {
			const ids = JSON.parse( window.localStorage.getItem( cancelledStorageKey ) || '[]' );
			return Array.isArray( ids ) ? ids : [];
		} catch ( err ) {
			return [];
		}
	}

	function rememberCancelledJob( jobId ) {
		if ( ! jobId ) return;
		const ids = readCancelledJobs().filter( ( id ) => id !== jobId );
		ids.unshift( jobId );
		try { window.localStorage.setItem( cancelledStorageKey, JSON.stringify( ids.slice( 0, 20 ) ) ); } catch ( err ) {}
	}

	function getCheckedValues( selector ) {
		return qAll( selector ).filter( ( item ) => item.checked ).map( ( item ) => item.value ).filter( Boolean );
	}

	function splitRefs( value ) {
		const refs = ( value || '' ).split( /[\s,]+/ ).map( ( item ) => item.trim() ).filter( Boolean );
		return {
			ids: refs.filter( ( item ) => /^\d+$/.test( item ) ).map( ( item ) => parseInt( item, 10 ) ),
			slugs: refs.filter( ( item ) => ! /^\d+$/.test( item ) ),
		};
	}

	function readSegments() {
		try {
			const parsed = JSON.parse( window.localStorage.getItem( segmentsKey ) || '{}' );
			return parsed && typeof parsed === 'object' ? parsed : {};
		} catch ( err ) {
			return {};
		}
	}

	function writeSegments( segments ) {
		try { window.localStorage.setItem( segmentsKey, JSON.stringify( segments ) ); } catch ( err ) {}
	}

	function captureSelection() {
		return {
			postType: q( '#sim-post-type' )?.value || 'post',
			postRefs: q( '#sim-post-refs' )?.value || '',
			statuses: getCheckedValues( '.sim-post-status' ),
			search: q( '#sim-post-search' )?.value || '',
			taxonomyFilters: q( '#sim-taxonomy-filters' )?.value || '',
			dateAfter: q( '#sim-date-after' )?.value || '',
			dateBefore: q( '#sim-date-before' )?.value || '',
			modifiedAfter: q( '#sim-modified-after' )?.value || '',
			modifiedBefore: q( '#sim-modified-before' )?.value || '',
			featuredFilter: q( '#sim-featured-filter' )?.value || 'any',
			contentFilter: q( '#sim-content-filter' )?.value || 'any',
			maxPosts: q( '#sim-max-posts' )?.value || '5000',
			mode: q( 'input[name="sim-run-mode"]:checked' )?.value || 'process',
			overwrite: !! q( '#sim-overwrite-featured' )?.checked,
			style: q( '#sim-gen-style' )?.value || 'photo',
		};
	}

	function applySelection( selection ) {
		if ( ! selection || typeof selection !== 'object' ) return;
		const setters = {
			'#sim-post-type': selection.postType,
			'#sim-post-refs': selection.postRefs,
			'#sim-post-search': selection.search,
			'#sim-taxonomy-filters': selection.taxonomyFilters,
			'#sim-date-after': selection.dateAfter,
			'#sim-date-before': selection.dateBefore,
			'#sim-modified-after': selection.modifiedAfter,
			'#sim-modified-before': selection.modifiedBefore,
			'#sim-featured-filter': selection.featuredFilter,
			'#sim-content-filter': selection.contentFilter,
			'#sim-max-posts': selection.maxPosts,
		};
		Object.entries( setters ).forEach( ( [ selector, value ] ) => {
			const el = q( selector );
			if ( el && value !== undefined ) el.value = value;
		} );
		const statuses = Array.isArray( selection.statuses ) ? selection.statuses : [];
		qAll( '.sim-post-status' ).forEach( ( item ) => {
			item.checked = statuses.includes( item.value );
		} );
		if ( selection.mode ) {
			const radio = q( 'input[name="sim-run-mode"][value="' + selection.mode + '"]' );
			if ( radio ) radio.checked = true;
		}
		const overwrite = q( '#sim-overwrite-featured' );
		if ( overwrite ) overwrite.checked = !! selection.overwrite;
		if ( selection.style ) {
			const style = q( '#sim-gen-style' );
			if ( style ) style.value = selection.style;
		}
		syncModeUi();
	}

	function populateSegments() {
		const select = q( '#sim-saved-segment' );
		if ( ! select ) return;
		const segments = readSegments();
		select.innerHTML = '<option value="">Saved selections…</option>';
		Object.keys( segments ).sort().forEach( ( name ) => {
			select.insertAdjacentHTML( 'beforeend', `<option value="${ escHtml( name ) }">${ escHtml( name ) }</option>` );
		} );
	}

	function saveCurrentSegment() {
		const name = window.prompt( 'Save this selection as:' );
		if ( ! name ) return;
		const segments = readSegments();
		segments[ name.trim() ] = captureSelection();
		writeSegments( segments );
		populateSegments();
	}

	function setTab( tab, extra ) {
		currentTab = tab === 'review' ? 'review' : 'run';
		qAll( '.sim-tab' ).forEach( ( el ) => {
			el.classList.toggle( 'is-on', el.dataset.tab === currentTab );
		} );
		qAll( '.sim-bulk-panel' ).forEach( ( el ) => {
			el.hidden = el.dataset.panel !== currentTab;
		} );
		const params = new URLSearchParams( window.location.search );
		params.set( 'page', 'smart-image-matcher-bulk' );
		params.set( 'sim_tab', currentTab );
		if ( currentTab === 'review' && ( extra === 'last' || reviewRun === 'last' ) ) {
			params.set( 'sim_run', 'last' );
			reviewRun = 'last';
		} else if ( currentTab === 'review' ) {
			params.set( 'sim_run', reviewRun );
		} else {
			params.delete( 'sim_run' );
		}
		const url = window.location.pathname + '?' + params.toString();
		window.history.replaceState( {}, '', url );
		if ( currentTab === 'review' ) {
			buildReview();
		} else if ( currentJob ) {
			buildProgress( currentJob );
		}
		syncToolsVisibility();
	}

	function filtersHtml() {
		let typeOptions = '';
		Object.entries( postTypes ).forEach( ( [ slug, label ] ) => {
			typeOptions += '<option value="' + escHtml( slug ) + '">' + escHtml( label ) + '</option>';
		} );
		const genDisabled = generationAvailable ? '' : ' disabled';
		const genNote = generationAvailable
			? 'Posts with no real featured image. Does not scan headings. Requires a confirm.'
			: 'Turn on on-demand generation in Settings and connect a provider.';
		return `
			<div class="sim-mode-cards">
				<label class="sim-mode-card is-on">
					<input type="radio" name="sim-run-mode" value="process" checked />
					<strong>Process articles</strong>
					<span>Insert strong library matches. Send the middle band to Review. Generate only if on-demand generation is on and the library would skip.</span>
				</label>
				<label class="sim-mode-card${ generationAvailable ? '' : ' is-disabled' }">
					<input type="radio" name="sim-run-mode" value="generate-featured"${ genDisabled } />
					<strong>Generate missing featured images</strong>
					<span>${ escHtml( genNote ) }</span>
				</label>
			</div>
			<div class="sim-saved-segment-row">
				<select id="sim-saved-segment"></select>
				<button type="button" class="button" id="sim-load-segment">Load</button>
				<button type="button" class="button" id="sim-save-segment">Save Current</button>
			</div>
			<div class="sim-form-grid sim-bulk-form-grid">
				<div class="sim-field">
					<label for="sim-post-type">Post Type</label>
					<select id="sim-post-type">${ typeOptions }</select>
				</div>
				<div class="sim-field">
					<label for="sim-featured-filter">Featured Image</label>
					<select id="sim-featured-filter">
						<option value="any">Any</option>
						<option value="missing">Missing featured image</option>
						<option value="has">Has featured image</option>
					</select>
				</div>
				<div class="sim-field sim-field-wide">
					<label for="sim-post-refs">Specific Posts</label>
					<textarea id="sim-post-refs" rows="3" placeholder="Leave blank for filtered results, or paste IDs/slugs"></textarea>
					<p class="description">Supports manual imports like <code>${ escHtml( samplePostRefs ) }</code>.</p>
				</div>
				<div class="sim-field sim-field-wide">
					<span class="sim-label">Post Status</span>
					<div class="sim-checkbox-grid">
						<label><input type="checkbox" class="sim-post-status" value="publish" checked /> Published</label>
						<label><input type="checkbox" class="sim-post-status" value="draft" checked /> Draft</label>
						<label><input type="checkbox" class="sim-post-status" value="pending" /> Pending</label>
						<label><input type="checkbox" class="sim-post-status" value="future" /> Scheduled</label>
						<label><input type="checkbox" class="sim-post-status" value="private" /> Private</label>
					</div>
				</div>
				<div class="sim-field">
					<label for="sim-post-search">Search</label>
					<input type="search" id="sim-post-search" placeholder="Title, content, excerpt, or slug contains…" />
				</div>
				<div class="sim-field">
					<label for="sim-content-filter">Content Filter</label>
					<select id="sim-content-filter">
						<option value="any">Any</option>
						<option value="has_headings">Has headings</option>
						<option value="no_images">No images in content</option>
						<option value="not_processed">Not processed by SIM</option>
					</select>
				</div>
				<div class="sim-field sim-field-wide">
					<label for="sim-taxonomy-filters">Taxonomy Filters</label>
					<textarea id="sim-taxonomy-filters" rows="2" placeholder="category:poultry,biosecurity; post_tag:avian-flu"></textarea>
				</div>
				<div class="sim-field sim-field-wide">
					<span class="sim-label">Date Filters</span>
					<div class="sim-date-grid">
						<label>Published after <input type="date" id="sim-date-after" /></label>
						<label>Published before <input type="date" id="sim-date-before" /></label>
						<label>Modified after <input type="date" id="sim-modified-after" /></label>
						<label>Modified before <input type="date" id="sim-modified-before" /></label>
					</div>
				</div>
				<div class="sim-field">
					<label for="sim-max-posts">Limit</label>
					<input type="number" id="sim-max-posts" value="5000" min="1" max="5000" step="50" />
				</div>
				<div class="sim-field sim-process-only">
					<label><input type="checkbox" id="sim-overwrite-featured" /> Replace existing featured images</label>
					<p class="description">When processing articles, overwrite a featured image that is already set.</p>
				</div>
				<div class="sim-field sim-generate-only" hidden>
					<label for="sim-gen-style">Image style</label>
					<select id="sim-gen-style">
						<option value="photo">Photo</option>
						<option value="illustration">Illustration</option>
					</select>
				</div>
			</div>`;
	}

	function bindIdleEvents( root ) {
		populateSegments();
		qAll( 'input[name="sim-run-mode"]', root ).forEach( ( radio ) => {
			radio.addEventListener( 'change', () => {
				qAll( '.sim-mode-card', root ).forEach( ( card ) => card.classList.remove( 'is-on' ) );
				radio.closest( '.sim-mode-card' )?.classList.add( 'is-on' );
				syncModeUi();
			} );
		} );
		q( '#sim-save-segment', root )?.addEventListener( 'click', saveCurrentSegment );
		q( '#sim-load-segment', root )?.addEventListener( 'click', () => {
			const name = q( '#sim-saved-segment' )?.value || '';
			const segments = readSegments();
			if ( name && segments[ name ] ) applySelection( segments[ name ] );
		} );
		q( '#sim-run-primary', root )?.addEventListener( 'click', onPrimaryClick );
		applyUrlPrefill();
		syncModeUi();
	}

	function syncModeUi() {
		const mode = q( 'input[name="sim-run-mode"]:checked' )?.value || 'process';
		const isGen = mode === 'generate-featured';
		qAll( '.sim-mode-card' ).forEach( ( card ) => {
			const radio = card.querySelector( 'input[name="sim-run-mode"]' );
			card.classList.toggle( 'is-on', !! radio && radio.checked );
		} );
		qAll( '.sim-process-only' ).forEach( ( el ) => { el.hidden = isGen; } );
		qAll( '.sim-generate-only' ).forEach( ( el ) => { el.hidden = ! isGen; } );
		const btn = q( '#sim-run-primary' );
		if ( btn ) {
			btn.textContent = isGen ? 'Generate featured…' : 'Process articles';
		}
		const help = q( '#sim-run-help' );
		if ( help ) {
			help.textContent = isGen
				? 'Only posts missing a real featured image are queued. You will confirm the count before anything is generated.'
				: 'Matches at or above the auto-insert threshold are written into the post. You will only review the middle band.';
		}
	}

	function applyUrlPrefill() {
		if ( ! app ) return;
		const mode = app.dataset.simMode || '';
		const ids = app.dataset.simPostIds || '';
		const postType = app.dataset.simPostType || '';
		if ( mode ) {
			const radio = q( 'input[name="sim-run-mode"][value="' + mode + '"]' );
			if ( radio && ! radio.disabled ) radio.checked = true;
		}
		if ( postType ) {
			const select = q( '#sim-post-type' );
			if ( select ) select.value = postType;
		}
		if ( ids ) {
			const refs = q( '#sim-post-refs' );
			if ( refs && ! refs.value ) refs.value = ids.replace( /,/g, ', ' );
		}
		const style = q( '#sim-gen-style' );
		if ( style && config.defaultStyle ) {
			style.value = config.defaultStyle === 'illustration' ? 'illustration' : 'photo';
		}
		app.dataset.simMode = '';
		app.dataset.simPostIds = '';
	}

	function buildIdle() {
		const root = q( '.sim-bulk-panel[data-panel="run"]' );
		if ( ! root ) return;
		root.innerHTML = `
			<div class="sim-card-head">
				<div>
					<h2>Run</h2>
					<p class="description">Choose a mode, filter posts, then process. Content can change during this run.</p>
				</div>
			</div>
			${ filtersHtml() }
			<div class="sim-step-actions">
				<button type="button" class="button button-primary" id="sim-run-primary">Process articles</button>
			</div>
			<p class="description" id="sim-run-help">Matches at or above the auto-insert threshold are written into the post. You will only review the middle band.</p>
			<div id="sim-run-notice"></div>`;
		bindIdleEvents( root );
		syncToolsVisibility();
	}

	function showRunNotice( message, type ) {
		const box = q( '#sim-run-notice' );
		if ( ! box ) return;
		box.innerHTML = `<div class="notice notice-${ type || 'info' } inline"><p>${ escHtml( message ) }</p></div>`;
	}

	function tilesHtml( jobData ) {
		const isGen = ( jobData.config && jobData.config.mode === 'generate-featured' ) || parseTotals( jobData ).config?.mode === 'generate-featured';
		if ( isGen ) {
			return `
				<div class="sim-metric-grid sim-metric-grid-four">
					<div class="sim-card sim-metric"><span>Queued</span><strong>${ getJobTotal( jobData ) }</strong></div>
					<div class="sim-card sim-metric"><span>Done</span><strong>${ getJobDone( jobData ) }</strong></div>
					<div class="sim-card sim-metric"><span>Generated</span><strong>${ outcome( jobData, 'generated' ) }</strong></div>
					<div class="sim-card sim-metric"><span>Skipped</span><strong>${ outcome( jobData, 'skipped' ) }</strong></div>
				</div>`;
		}
		return `
			<div class="sim-metric-grid sim-metric-grid-four">
				<div class="sim-card sim-metric"><span>Inserted</span><strong>${ outcome( jobData, 'inserted' ) }</strong></div>
				<div class="sim-card sim-metric"><span>Review</span><strong>${ outcome( jobData, 'review' ) }</strong></div>
				<div class="sim-card sim-metric"><span>Generated</span><strong>${ outcome( jobData, 'generated' ) }</strong></div>
				<div class="sim-card sim-metric"><span>Skipped</span><strong>${ outcome( jobData, 'skipped' ) }</strong></div>
			</div>`;
	}

	function statusClass( status ) {
		if ( status === 'completed' ) return 'sim-status-good';
		if ( status === 'failed' || status === 'cancelled' ) return 'sim-status-warn';
		return 'sim-status-info';
	}

	function buildProgress( jobData ) {
		const root = q( '.sim-bulk-panel[data-panel="run"]' );
		if ( ! root ) return;
		const total = getJobTotal( jobData );
		const done = getJobDone( jobData );
		const pct = total > 0 ? Math.min( 100, Math.round( ( done / total ) * 100 ) ) : 0;
		const status = jobData.status || 'queued';
		const label = jobData.label || '';
		const running = status === 'queued' || status === 'processing';
		const reviewCount = outcome( jobData, 'review' );
		let actions = '';
		if ( running ) {
			actions = `<button type="button" class="button" id="sim-cancel-run">${ i18n.cancel }</button>`;
		} else if ( status === 'completed' && reviewCount > 0 ) {
			actions = `<button type="button" class="button button-primary" id="sim-goto-review">Review ${ reviewCount } articles</button>
				<button type="button" class="button" id="sim-run-again">Run again</button>`;
		} else {
			actions = `<button type="button" class="button button-primary" id="sim-run-again">Run again</button>`;
		}
		const doneCopy = status === 'completed' && reviewCount === 0 ? '<p class="description">Nothing landed in Review.</p>' : '';
		const modeLabel = ( jobData.config && jobData.config.mode === 'generate-featured' ) ? 'Generate featured' : 'Process articles';
		const changeLabel = i18n.changeSelection || 'Change selection';
		root.innerHTML = `
			<div class="sim-card-head">
				<div>
					<h2>Run</h2>
					<p class="description">${ escHtml( label ) }</p>
				</div>
				<span class="sim-status ${ statusClass( status ) }">${ escHtml( status.charAt( 0 ).toUpperCase() + status.slice( 1 ) ) }</span>
			</div>
			<div class="sim-run-summary">
				<span>Posts · ${ total } selected · ${ escHtml( modeLabel ) }</span>
				<button type="button" class="button-link" id="sim-change-selection">${ escHtml( changeLabel ) }</button>
			</div>
			<p class="description">Processing ${ done } of ${ total } articles</p>
			<div class="sim-progress-bar"><div class="sim-progress-fill" style="width:${ pct }%"></div></div>
			${ tilesHtml( jobData ) }
			${ doneCopy }
			<div class="sim-step-actions">${ actions }</div>`;
		q( '#sim-cancel-run', root )?.addEventListener( 'click', () => cancelJob( jobData.job_id ) );
		q( '#sim-goto-review', root )?.addEventListener( 'click', () => {
			reviewRun = 'last';
			setTab( 'review', 'last' );
		} );
		q( '#sim-run-again', root )?.addEventListener( 'click', () => {
			forgetJob();
			currentJob = null;
			buildIdle();
			syncToolsVisibility();
		} );
		q( '#sim-change-selection', root )?.addEventListener( 'click', () => onChangeSelection( jobData ) );
		const pill = q( '#sim-run-pill' );
		if ( pill ) pill.hidden = ! running;
		syncToolsVisibility();
	}

	async function onChangeSelection( jobData ) {
		const running = jobData && ( jobData.status === 'queued' || jobData.status === 'processing' );
		if ( running ) {
			if ( ! window.confirm( i18n.confirmCancelRun || 'Cancel the current run so you can change the selection?' ) ) {
				return;
			}
			await cancelJob( jobData.job_id );
		}
		forgetJob();
		currentJob = null;
		buildIdle();
		syncToolsVisibility();
	}

	function jobBody( mode ) {
		const selection = captureSelection();
		const refs = splitRefs( selection.postRefs );
		const body = {
			post_type: selection.postType,
			post_statuses: selection.statuses,
			search: selection.search,
			taxonomy_filters: selection.taxonomyFilters,
			date_after: selection.dateAfter,
			date_before: selection.dateBefore,
			modified_after: selection.modifiedAfter,
			modified_before: selection.modifiedBefore,
			featured_filter: selection.featuredFilter,
			content_filter: selection.contentFilter,
			max_posts: parseInt( selection.maxPosts || '5000', 10 ),
			mode,
			overwrite: !! selection.overwrite,
			style: selection.style === 'illustration' ? 'illustration' : 'photo',
		};
		if ( refs.ids.length ) body.post_ids = refs.ids;
		if ( refs.slugs.length ) body.post_slugs = refs.slugs;
		return body;
	}

	async function onPrimaryClick() {
		const mode = q( 'input[name="sim-run-mode"]:checked' )?.value || 'process';
		if ( mode === 'generate-featured' ) {
			await prepareGenerateConfirm();
			return;
		}
		await startJob( 'process' );
	}

	async function prepareGenerateConfirm() {
		if ( ! apiFetch ) {
			showRunNotice( 'wp.apiFetch is unavailable.', 'error' );
			return;
		}
		try {
			const data = await apiFetch( {
				path: '/smart-image-matcher/v1/jobs',
				method: 'POST',
				data: Object.assign( {}, jobBody( 'generate-featured' ), { dry_run: true } ),
			} );
			const total = parseInt( data.total || 0, 10 );
			if ( total < 1 ) {
				showRunNotice( i18n.noMissingFeatured || 'No posts in this selection are missing a featured image.', 'warning' );
				return;
			}
			openGenerateModal( total );
		} catch ( err ) {
			const msg = ( err && err.message ) ? err.message : 'Could not count posts.';
			showRunNotice( msg, 'error' );
		}
	}

	function openGenerateModal( total ) {
		const modal = q( '#sim-gen-modal' );
		if ( ! modal ) return;
		const n = parseInt( total || 0, 10 );
		const body = q( '#sim-gen-modal-body' );
		if ( body ) {
			body.innerHTML = `This will queue <strong>${ n }</strong> featured image(s) for posts with no real featured image.`;
		}
		const confirm = q( '#sim-gen-confirm' );
		if ( confirm ) confirm.textContent = 'Generate ' + n + ' featured images';
		modal.hidden = false;
	}

	function closeGenerateModal() {
		const modal = q( '#sim-gen-modal' );
		if ( modal ) modal.hidden = true;
	}

	async function startJob( mode ) {
		if ( ! apiFetch ) {
			showRunNotice( 'wp.apiFetch is unavailable.', 'error' );
			return;
		}
		try {
			const data = await apiFetch( {
				path: '/smart-image-matcher/v1/jobs',
				method: 'POST',
				data: jobBody( mode ),
			} );
			currentJob = data;
			rememberJob( data.job_id );
			buildProgress( data );
			startPolling( data.job_id );
		} catch ( err ) {
			const msg = ( err && err.message ) ? err.message : 'Could not start the run.';
			showRunNotice( msg, 'error' );
		}
	}

	function startPolling( jobId ) {
		if ( pollTimer ) window.clearInterval( pollTimer );
		pollTimer = window.setInterval( () => pollJob( jobId ), 2000 );
		pollJob( jobId );
	}

	async function pollJob( jobId ) {
		if ( ! apiFetch ) return;
		try {
			const data = await apiFetch( {
				path: '/smart-image-matcher/v1/jobs/' + encodeURIComponent( jobId ),
				method: 'GET',
			} );
			currentJob = data;
			if ( currentTab === 'run' ) {
				buildProgress( data );
			}
			const status = data.status || '';
			if ( [ 'completed', 'failed', 'cancelled' ].includes( status ) ) {
				window.clearInterval( pollTimer );
				pollTimer = null;
				if ( status !== 'cancelled' ) {
					forgetJob();
				}
			}
		} catch ( err ) {
			window.clearInterval( pollTimer );
			pollTimer = null;
		}
	}

	async function cancelJob( jobId ) {
		if ( ! jobId || ! apiFetch ) return;
		rememberCancelledJob( jobId );
		try {
			await apiFetch( {
				path: '/smart-image-matcher/v1/jobs/' + encodeURIComponent( jobId ) + '/cancel',
				method: 'POST',
			} );
		} catch ( err ) {}
		forgetJob();
		window.clearInterval( pollTimer );
		pollTimer = null;
		currentJob = Object.assign( {}, currentJob || {}, { status: 'cancelled' } );
		buildProgress( currentJob );
	}

	function slotLabel( heading ) {
		const tag = ( heading.heading_tag || '' ).toLowerCase();
		const hash = heading.heading_hash || '';
		if ( tag === 'featured' || hash === 'featured' ) {
			return 'Featured image';
		}
		const level = ( tag || 'h2' ).toUpperCase();
		return level + ' ' + ( heading.heading_text || '' );
	}

	function scoreClass( score ) {
		if ( score >= autoInsert ) return 'sim-confidence-high';
		if ( score >= ( parseInt( config.reviewThreshold || '70', 10 ) ) ) return 'sim-confidence-medium';
		return 'sim-confidence-low';
	}

	function buildReview() {
		const root = q( '.sim-bulk-panel[data-panel="review"]' );
		if ( ! root ) return;
		root.innerHTML = `
			<div class="sim-review-toolbar">
				<div class="sim-review-filters">
					<span class="sim-label">Slot</span>
					<button type="button" class="button sim-slot-btn${ reviewSlot === 'all' ? ' button-primary' : '' }" data-slot="all">All</button>
					<button type="button" class="button sim-slot-btn${ reviewSlot === 'heading' ? ' button-primary' : '' }" data-slot="heading">Headings</button>
					<button type="button" class="button sim-slot-btn${ reviewSlot === 'featured' ? ' button-primary' : '' }" data-slot="featured">Featured</button>
					<span class="sim-label">Source</span>
					<button type="button" class="button sim-run-btn${ reviewRun === 'all' ? ' button-primary' : '' }" data-run="all">${ escHtml( i18n.allPending ) }</button>
					<button type="button" class="button sim-run-btn${ reviewRun === 'last' ? ' button-primary' : '' }" data-run="last">${ escHtml( i18n.lastRun ) }</button>
				</div>
				<div class="sim-review-actions">
					<button type="button" class="button" id="sim-approve-above">Approve all ≥ ${ autoInsert }%</button>
					<button type="button" class="button button-primary" id="sim-insert-approved">${ escHtml( i18n.insertApproved ) }</button>
				</div>
			</div>
			<div id="sim-review-list"><p>Loading…</p></div>
			<div id="sim-review-pagination"></div>`;
		qAll( '.sim-slot-btn', root ).forEach( ( btn ) => {
			btn.addEventListener( 'click', () => {
				reviewSlot = btn.dataset.slot || 'all';
				reviewPage = 1;
				buildReview();
			} );
		} );
		qAll( '.sim-run-btn', root ).forEach( ( btn ) => {
			btn.addEventListener( 'click', () => {
				reviewRun = btn.dataset.run === 'last' ? 'last' : 'all';
				reviewPage = 1;
				setTab( 'review' );
			} );
		} );
		q( '#sim-approve-above', root )?.addEventListener( 'click', approveAbove );
		q( '#sim-insert-approved', root )?.addEventListener( 'click', insertApproved );
		loadReview();
	}

	async function loadReview() {
		const wrap = q( '#sim-review-list' );
		if ( ! wrap || ! apiFetch ) return;
		try {
			const data = await apiFetch( {
				path: `/smart-image-matcher/v1/review?page=${ reviewPage }&per_page=20&slot=${ encodeURIComponent( reviewSlot ) }&run=${ encodeURIComponent( reviewRun ) }`,
				method: 'GET',
			} );
			const articles = data.articles || [];
			if ( ! articles.length ) {
				if ( reviewRun === 'last' ) {
					wrap.innerHTML = `<div class="sim-empty"><p>This run has no review items.</p><p class="description"><button type="button" class="button" id="sim-switch-all-pending">Show all pending</button></p></div>`;
					q( '#sim-switch-all-pending', wrap )?.addEventListener( 'click', () => {
						reviewRun = 'all';
						setTab( 'review' );
					} );
				} else {
					wrap.innerHTML = `<div class="sim-empty"><p>${ escHtml( i18n.noMatches ) }</p><p class="description">Strong matches already inserted. Cron leftovers appear here when they need a human.</p><p><button type="button" class="button button-primary" id="sim-empty-to-run">Process articles</button></p></div>`;
					q( '#sim-empty-to-run', wrap )?.addEventListener( 'click', () => setTab( 'run' ) );
				}
				q( '#sim-review-pagination' ).innerHTML = '';
				return;
			}
			let html = '';
			articles.forEach( ( article ) => {
				const headings = article.headings || [];
				html += `<article class="sim-article">
					<div class="sim-article-head">
						<h3><a href="${ escHtml( article.edit_url || '#' ) }" target="_blank" rel="noopener">${ escHtml( article.post_title || ( '#' + article.post_id ) ) }</a> <code>${ article.post_id }</code></h3>
						<span>${ headings.length } to review</span>
					</div>`;
				headings.forEach( ( heading ) => {
					const score = parseInt( heading.confidence_score || 0, 10 );
					const thumb = heading.image_url
						? `<img src="${ escHtml( heading.image_url ) }" alt="" width="72" height="54" />`
						: '<span class="sim-no-thumb">—</span>';
					html += `<div class="sim-slot-row" data-match-id="${ heading.id }">
						<div class="sim-slot-thumb">${ thumb }</div>
						<div class="sim-slot-text">${ escHtml( slotLabel( heading ) ) }</div>
						<div class="${ scoreClass( score ) }">${ score }%</div>
						<div class="sim-slot-actions">
							<button type="button" class="button button-small sim-approve-btn" data-match="${ heading.id }">${ escHtml( i18n.approve ) }</button>
							<button type="button" class="button button-small sim-reject-btn" data-match="${ heading.id }">${ escHtml( i18n.reject ) }</button>
						</div>
					</div>`;
				} );
				html += '</article>';
			} );
			wrap.innerHTML = html;
			qAll( '.sim-approve-btn', wrap ).forEach( ( btn ) => {
				btn.addEventListener( 'click', () => updateMatch( parseInt( btn.dataset.match, 10 ), 'approved' ) );
			} );
			qAll( '.sim-reject-btn', wrap ).forEach( ( btn ) => {
				btn.addEventListener( 'click', () => updateMatch( parseInt( btn.dataset.match, 10 ), 'rejected' ) );
			} );
			const total = parseInt( data.total_articles || 0, 10 );
			const pages = Math.ceil( total / 20 );
			const pager = q( '#sim-review-pagination' );
			if ( pages > 1 ) {
				pager.innerHTML = `<button type="button" class="button" id="sim-rev-prev"${ reviewPage <= 1 ? ' disabled' : '' }>Previous</button>
					<span>Page ${ reviewPage } of ${ pages }</span>
					<button type="button" class="button" id="sim-rev-next"${ reviewPage >= pages ? ' disabled' : '' }>Next</button>`;
				q( '#sim-rev-prev' )?.addEventListener( 'click', () => { reviewPage -= 1; loadReview(); } );
				q( '#sim-rev-next' )?.addEventListener( 'click', () => { reviewPage += 1; loadReview(); } );
			} else {
				pager.innerHTML = '';
			}
		} catch ( err ) {
			wrap.innerHTML = `<p class="notice notice-error inline">${ escHtml( err.message || 'Could not load review.' ) }</p>`;
		}
	}

	async function updateMatch( matchId, status ) {
		if ( ! apiFetch ) return;
		try {
			await apiFetch( {
				path: '/smart-image-matcher/v1/matches/' + matchId,
				method: 'POST',
				data: { status },
			} );
			const row = q( `.sim-slot-row[data-match-id="${ matchId }"]` );
			if ( row && status === 'rejected' ) {
				row.classList.add( 'is-rejected' );
			}
			if ( row && status === 'approved' ) {
				row.classList.add( 'is-approved' );
			}
		} catch ( err ) {
			window.alert( err.message || 'Could not update match.' );
		}
	}

	async function approveAbove() {
		if ( ! apiFetch ) return;
		try {
			await apiFetch( {
				path: '/smart-image-matcher/v1/review/approve-above',
				method: 'POST',
				data: { run: reviewRun },
			} );
			loadReview();
		} catch ( err ) {
			window.alert( err.message || 'Could not approve matches.' );
		}
	}

	async function insertApproved() {
		if ( ! apiFetch ) return;
		try {
			await apiFetch( {
				path: '/smart-image-matcher/v1/review/insert-approved',
				method: 'POST',
			} );
			loadReview();
		} catch ( err ) {
			window.alert( err.message || 'Could not queue inserts.' );
		}
	}

	async function restoreJob() {
		let jobId = null;
		try { jobId = window.localStorage.getItem( storageKey ); } catch ( err ) {}
		if ( ! jobId || ! apiFetch ) {
			buildIdle();
			return;
		}
		if ( readCancelledJobs().includes( jobId ) ) {
			forgetJob();
			buildIdle();
			return;
		}
		try {
			const data = await apiFetch( {
				path: '/smart-image-matcher/v1/jobs/' + encodeURIComponent( jobId ),
				method: 'GET',
			} );
			currentJob = data;
			const status = data.status || '';
			if ( status === 'queued' || status === 'processing' ) {
				buildProgress( data );
				startPolling( jobId );
			} else if ( status === 'completed' || status === 'failed' || status === 'cancelled' ) {
				buildProgress( data );
			} else {
				buildIdle();
			}
		} catch ( err ) {
			forgetJob();
			buildIdle();
		}
	}

	function syncToolsVisibility() {
		const running = !!( currentJob && ( currentJob.status === 'queued' || currentJob.status === 'processing' ) );
		const runTools = q( '#sim-run-tools' );
		const reviewTools = q( '#sim-review-tools' );
		if ( runTools ) {
			runTools.hidden = currentTab !== 'run' || running;
		}
		if ( reviewTools ) {
			reviewTools.hidden = currentTab !== 'review';
		}
	}

	function sprintf( template, ...args ) {
		let i = 0;
		return String( template || '' ).replace( /%d/g, () => String( args[ i++ ] || 0 ) );
	}

	let recoveryPreview = null;

	function recoveryNotice( type, message ) {
		const box = q( '#sim-fal-recovery-notice' );
		if ( ! box ) return;
		box.innerHTML = `<div class="notice notice-${ type || 'info' } inline"><p>${ escHtml( message ) }</p></div>`;
	}

	function recoveryHours() {
		return parseInt( q( '#sim-fal-recovery-hours' )?.value || '48', 10 );
	}

	function recoveryStatuses() {
		const statuses = getCheckedValues( '.sim-post-status' );
		return statuses.length ? statuses : [ 'publish' ];
	}

	function renderRecoveryPreview( result ) {
		const matched = Array.isArray( result.matched ) ? result.matched : [];
		const unmatched = Array.isArray( result.unmatched ) ? result.unmatched : [];
		const summary = q( '#sim-fal-recovery-summary' );
		const table = q( '#sim-fal-recovery-table' );
		const body = q( '#sim-fal-recovery-body' );
		if ( q( '#sim-fal-recovery-matched' ) ) q( '#sim-fal-recovery-matched' ).textContent = String( matched.length );
		if ( q( '#sim-fal-recovery-unmatched' ) ) q( '#sim-fal-recovery-unmatched' ).textContent = String( unmatched.length );
		if ( summary ) summary.hidden = false;
		if ( ! table || ! body ) return;
		const rows = [];
		matched.forEach( ( item ) => {
			const prompt = String( item.prompt || '' );
			rows.push( `<tr><td>${ escHtml( item.post_title || ( '#' + item.post_id ) ) }</td><td>${ escHtml( item.request_id || '' ) }</td><td>${ escHtml( prompt.length > 140 ? prompt.slice( 0, 137 ) + '…' : prompt ) }</td><td><span class="sim-status sim-status-good">Safe match</span></td></tr>` );
		} );
		unmatched.forEach( ( item ) => {
			const prompt = String( item.prompt || '' );
			const reason = item.reason ? String( item.reason ) : 'Not matched';
			rows.push( `<tr><td>${ escHtml( item.near_post_title || '—' ) }</td><td>${ escHtml( item.request_id || '' ) }</td><td>${ escHtml( prompt.length > 140 ? prompt.slice( 0, 137 ) + '…' : prompt ) }</td><td><span class="sim-status sim-status-warn">${ escHtml( reason ) }</span></td></tr>` );
		} );
		body.innerHTML = rows.join( '' );
		table.hidden = rows.length === 0;
		const runBtn = q( '#sim-fal-recovery-run-button' );
		if ( runBtn ) runBtn.disabled = matched.length === 0;
	}

	async function previewRecovery() {
		if ( ! apiFetch ) return;
		recoveryPreview = null;
		recoveryNotice( 'info', 'Checking recent completed fal.ai images…' );
		try {
			const result = await apiFetch( {
				path: '/smart-image-matcher/v1/generate-images/recover',
				method: 'POST',
				data: {
					discover_recent: true,
					dry_run: true,
					hours: recoveryHours(),
					post_statuses: recoveryStatuses().join( ',' ),
				},
			} );
			recoveryPreview = result;
			renderRecoveryPreview( result );
			const matched = Array.isArray( result.matched ) ? result.matched.length : 0;
			const unmatched = Array.isArray( result.unmatched ) ? result.unmatched.length : 0;
			recoveryNotice( matched > 0 ? 'success' : 'warning', matched > 0 ? ( matched + ' safe match(es) found; ' + unmatched + ' will remain untouched.' ) : 'No safe matches were found. Nothing will be imported.' );
		} catch ( err ) {
			recoveryNotice( 'error', err.message || 'Could not preview fal.ai recovery.' );
		}
	}

	async function runRecovery() {
		const matched = recoveryPreview && Array.isArray( recoveryPreview.matched ) ? recoveryPreview.matched.length : 0;
		if ( matched <= 0 ) return;
		if ( ! window.confirm( sprintf( i18n.confirmRecovery || 'Recover %d matched image(s) into WordPress?', matched ) ) ) {
			return;
		}
		try {
			const result = await apiFetch( {
				path: '/smart-image-matcher/v1/generate-images/recover',
				method: 'POST',
				data: {
					discover_recent: true,
					hours: recoveryHours(),
					post_statuses: recoveryStatuses().join( ',' ),
				},
			} );
			const jobs = Array.isArray( result.jobs ) ? result.jobs : [];
			recoveryNotice( jobs.length ? 'info' : 'error', jobs.length ? ( jobs.length + ' image(s) queued for recovery.' ) : 'Could not queue fal.ai recovery.' );
			recoveryPreview = null;
			const runBtn = q( '#sim-fal-recovery-run-button' );
			if ( runBtn ) runBtn.disabled = true;
		} catch ( err ) {
			recoveryNotice( 'error', err.message || 'Could not queue fal.ai recovery.' );
		}
	}

	let lastAuditScan = null;

	function auditNotice( type, message ) {
		const box = q( '#sim-fiaa-audit-notice' );
		if ( ! box ) return;
		box.innerHTML = `<div class="notice notice-${ type || 'info' } inline"><p>${ escHtml( message ) }</p></div>`;
	}

	function auditSettings() {
		const statuses = qAll( '.sim-audit-status' ).filter( ( item ) => item.checked ).map( ( item ) => item.value );
		return {
			post_type: q( '#sim-audit-post-type' )?.value || 'post',
			post_statuses: statuses.length ? statuses : [ 'publish', 'draft' ],
		};
	}

	function renderAuditResults( result ) {
		const total = parseInt( result.total_assigned || 0, 10 );
		const safe = parseInt( result.safe || 0, 10 );
		const unsafe = parseInt( result.unsafe || 0, 10 );
		const preview = Array.isArray( result.preview ) ? result.preview : [];
		const summary = q( '#sim-fiaa-audit-summary' );
		const table = q( '#sim-fiaa-audit-table' );
		const body = q( '#sim-fiaa-audit-body' );
		if ( q( '#sim-fiaa-audit-total-assigned' ) ) q( '#sim-fiaa-audit-total-assigned' ).textContent = String( total );
		if ( q( '#sim-fiaa-audit-safe' ) ) q( '#sim-fiaa-audit-safe' ).textContent = String( safe );
		if ( q( '#sim-fiaa-audit-unsafe' ) ) q( '#sim-fiaa-audit-unsafe' ).textContent = String( unsafe );
		if ( summary ) summary.hidden = false;
		if ( table && body ) {
			if ( preview.length ) {
				table.hidden = false;
				body.innerHTML = preview.map( ( item ) => `<tr><td>${ escHtml( item.title || ( '#' + item.id ) ) }</td><td><code>${ escHtml( item.post_slug || '' ) }</code></td><td><code>${ escHtml( item.image_slug || '' ) }</code></td><td>${ escHtml( item.method || '' ) }</td><td>${ escHtml( String( parseInt( item.score || 0, 10 ) ) ) }%</td></tr>` ).join( '' );
			} else {
				table.hidden = true;
				body.innerHTML = '';
			}
		}
		const note = q( '#sim-fiaa-audit-preview-note' );
		if ( note ) {
			if ( unsafe > preview.length && preview.length > 0 ) {
				note.hidden = false;
				note.textContent = 'Showing the first ' + preview.length + ' of ' + unsafe + ' unsafe posts.';
			} else {
				note.hidden = true;
			}
		}
		const clearBtn = q( '#sim-fiaa-audit-clear-button' );
		if ( clearBtn ) clearBtn.disabled = unsafe <= 0;
	}

	async function scanAudit() {
		if ( ! apiFetch ) return;
		const settings = auditSettings();
		auditNotice( 'info', 'Scanning featured images…' );
		try {
			const params = new URLSearchParams();
			params.set( 'post_type', settings.post_type );
			settings.post_statuses.forEach( ( status ) => params.append( 'post_statuses[]', status ) );
			const result = await apiFetch( {
				path: '/smart-image-matcher/v1/featured-image-audit?' + params.toString(),
				method: 'GET',
			} );
			lastAuditScan = result;
			renderAuditResults( result );
			const unsafe = parseInt( result.unsafe || 0, 10 );
			auditNotice( unsafe > 0 ? 'warning' : 'success', unsafe > 0 ? ( 'Found ' + unsafe + ' unsafe featured image(s).' ) : 'No unsafe featured images were found.' );
		} catch ( err ) {
			auditNotice( 'error', err.message || 'Could not scan featured images.' );
		}
	}

	async function clearAudit() {
		if ( ! apiFetch ) return;
		const unsafe = lastAuditScan ? parseInt( lastAuditScan.unsafe || 0, 10 ) : 0;
		if ( unsafe <= 0 ) {
			auditNotice( 'error', 'No unsafe featured images were found.' );
			return;
		}
		if ( ! window.confirm( i18n.confirmAuditClear || 'Remove unsafe featured images from the scanned posts?' ) ) {
			return;
		}
		try {
			const job = await apiFetch( {
				path: '/smart-image-matcher/v1/featured-image-audit/clear',
				method: 'POST',
				data: auditSettings(),
			} );
			if ( ! job.job_id ) {
				auditNotice( 'success', job.message || 'No unsafe featured images were found.' );
				lastAuditScan = { total_assigned: 0, safe: 0, unsafe: 0, preview: [] };
				renderAuditResults( lastAuditScan );
				return;
			}
			auditNotice( 'info', 'Cleanup queued. Refresh this panel in a minute if counts have not dropped.' );
		} catch ( err ) {
			auditNotice( 'error', err.message || 'Could not start cleanup.' );
		}
	}

	function fillAuditPostTypes() {
		const select = q( '#sim-audit-post-type' );
		if ( ! select ) return;
		select.innerHTML = '';
		Object.entries( postTypes ).forEach( ( [ slug, label ] ) => {
			select.insertAdjacentHTML( 'beforeend', `<option value="${ escHtml( slug ) }">${ escHtml( label ) }</option>` );
		} );
	}

	function bindTools() {
		fillAuditPostTypes();
		q( '#sim-fal-recovery-preview-button' )?.addEventListener( 'click', previewRecovery );
		q( '#sim-fal-recovery-run-button' )?.addEventListener( 'click', runRecovery );
		q( '#sim-fiaa-audit-scan-button' )?.addEventListener( 'click', scanAudit );
		q( '#sim-fiaa-audit-clear-button' )?.addEventListener( 'click', clearAudit );
	}

	function bindChrome() {
		qAll( '.sim-tab' ).forEach( ( tab ) => {
			tab.addEventListener( 'click', ( event ) => {
				event.preventDefault();
				setTab( tab.dataset.tab );
			} );
		} );
		qAll( '[data-sim-gen-cancel]' ).forEach( ( el ) => {
			el.addEventListener( 'click', closeGenerateModal );
		} );
		q( '#sim-gen-confirm' )?.addEventListener( 'click', async () => {
			closeGenerateModal();
			await startJob( 'generate-featured' );
		} );
	}

	if ( ! app ) {
		return;
	}

	if ( ! apiFetch ) {
		const root = q( '.sim-bulk-panel[data-panel="run"]' ) || app;
		root.innerHTML = '<div class="notice notice-error inline"><p>Bulk Processor could not load because wp.apiFetch is unavailable.</p></div>';
		return;
	}

	bindChrome();
	bindTools();
	syncToolsVisibility();
	if ( currentTab === 'review' ) {
		buildReview();
		restoreJob();
	} else {
		restoreJob();
	}
}() );
