/**
 * bulk.js - Bulk Processor SPA (Premium).
 *
 * Four-step flow:
 *   Step 1: Select Posts  → choose post type, optionally filter by IDs
 *   Step 2: Configure     → mode, confidence threshold
 *   Step 3: Find Matches  → queues and polls background matching jobs
 *   Step 4: Review & Insert → approve / reject / swap, then insert
 *
 * Communicates with /wp-json/smart-image-matcher/v1/jobs/* REST endpoints.
 * Uses wp.apiFetch for nonce-aware requests.
 * Uses SimIcons for all iconography; no emoji.
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
	const i18n = Object.assign( {
		selectPosts: 'Select Posts',
		configure: 'Configure',
		findMatches: 'Find Matches',
		reviewInsert: 'Review & Insert',
		approve: 'Approve',
		reject: 'Reject',
		insertApproved: 'Insert Approved',
		cancel: 'Cancel',
		cancelReview: 'Cancel Review',
		noMatches: 'No matches found.',
	}, config.i18n || {} );
	const Icons = window.SimIcons || {};
	const apiFetch = window.wp && window.wp.apiFetch;
	const samplePostRefs = config.samplePostRefs || '123, 456, sample-post-slug';

	if ( apiFetch && apiFetch.createNonceMiddleware && nonce ) {
		apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );
	}

	// -------------------------------------------------------------------------
	// State
	// -------------------------------------------------------------------------

	let currentStep = 1;
	let currentJob  = null;
	let pollTimer   = null;
	const storageKey = 'smartImageMatcherBulkCurrentJobId';
	const cancelledStorageKey = 'smartImageMatcherBulkCancelledJobIds';
	const segmentsKey = 'smartImageMatcherBulkSelectionSegments';

	// -------------------------------------------------------------------------
	// DOM helpers
	// -------------------------------------------------------------------------

	function q( selector, root ) {
		return ( root || document ).querySelector( selector );
	}

	function qAll( selector, root ) {
		return Array.from( ( root || document ).querySelectorAll( selector ) );
	}

	function escHtml( str ) {
		const d = document.createElement( 'div' );
		d.textContent = str;
		return d.innerHTML;
	}

	function showBootError( message ) {
		const root = q( '.sim-bulk-step[data-step="1"]' ) || q( '#sim-bulk-app' );
		if ( ! root ) {
			return;
		}

		root.innerHTML = `
			<div class="notice notice-error inline">
				<p><strong>Bulk Processor could not load.</strong></p>
				<p>${ escHtml( message ) }</p>
			</div>`;
		showStep( 1 );
	}

	function showStepNotice( step, message, type = 'info' ) {
		const root = q( `.sim-bulk-step[data-step="${ step }"]` );
		if ( ! root ) {
			return;
		}

		const existing = q( '.sim-bulk-notice', root );
		if ( existing ) {
			existing.remove();
		}

		root.insertAdjacentHTML(
			'afterbegin',
			`<div class="notice notice-${ type } inline sim-bulk-notice"><p>${ escHtml( message ) }</p></div>`
		);
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

		return {};
	}

	function getJobTotal( jobData ) {
		const totals = parseTotals( jobData );
		return parseInt( jobData.total || totals.total || 0, 10 );
	}

	function getJobDone( jobData ) {
		const totals = parseTotals( jobData );
		return parseInt( jobData.done || totals.done || 0, 10 );
	}

	function getJobPostType( jobData ) {
		const totals = parseTotals( jobData );
		const config = totals.config || jobData.config || {};
		return jobData.post_type || config.post_type || '';
	}

	function rememberJob( jobId ) {
		try {
			window.localStorage.setItem( storageKey, jobId );
		} catch ( err ) {}
	}

	function forgetJob() {
		try {
			window.localStorage.removeItem( storageKey );
		} catch ( err ) {}
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

		const ids = readCancelledJobs().filter( id => id !== jobId );
		ids.unshift( jobId );

		try {
			window.localStorage.setItem( cancelledStorageKey, JSON.stringify( ids.slice( 0, 20 ) ) );
		} catch ( err ) {}
	}

	function isCancelledJobRemembered( jobId ) {
		return readCancelledJobs().includes( jobId );
	}

	function getCheckedValues( selector ) {
		return qAll( selector )
			.filter( item => item.checked )
			.map( item => item.value )
			.filter( Boolean );
	}

	function splitRefs( value ) {
		const refs = ( value || '' )
			.split( /[\s,]+/ )
			.map( item => item.trim() )
			.filter( Boolean );

		return {
			ids: refs.filter( item => /^\d+$/.test( item ) ).map( item => parseInt( item, 10 ) ),
			slugs: refs.filter( item => ! /^\d+$/.test( item ) ),
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
		try {
			window.localStorage.setItem( segmentsKey, JSON.stringify( segments ) );
		} catch ( err ) {}
	}

	function captureSelection() {
		return {
			postType: q( '#sim-post-type' )?.value || 'post',
			postRefs: q( '#sim-post-refs' )?.value || '',
			statuses: getCheckedValues( '.sim-post-status:checked' ),
			search: q( '#sim-post-search' )?.value || '',
			taxonomyFilters: q( '#sim-taxonomy-filters' )?.value || '',
			dateAfter: q( '#sim-date-after' )?.value || '',
			dateBefore: q( '#sim-date-before' )?.value || '',
			modifiedAfter: q( '#sim-modified-after' )?.value || '',
			modifiedBefore: q( '#sim-modified-before' )?.value || '',
			featuredFilter: q( '#sim-featured-filter' )?.value || 'any',
			contentFilter: q( '#sim-content-filter' )?.value || 'any',
			maxPosts: q( '#sim-max-posts' )?.value || '5000',
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
		qAll( '.sim-post-status' ).forEach( item => {
			item.checked = statuses.includes( item.value );
		} );
	}

	function populateSegments() {
		const select = q( '#sim-saved-segment' );
		if ( ! select ) return;

		const segments = readSegments();
		select.innerHTML = '<option value="">Saved selections...</option>';
		Object.keys( segments ).sort().forEach( name => {
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

	// -------------------------------------------------------------------------
	// Step rendering
	// -------------------------------------------------------------------------

	function showStep( step ) {
		currentStep = step;
		qAll( '.sim-bulk-step' ).forEach( el => {
			el.style.display = ( parseInt( el.dataset.step, 10 ) === step ) ? '' : 'none';
		} );
		// Update breadcrumb.
		qAll( '.sim-step-indicator' ).forEach( el => {
			const n = parseInt( el.dataset.step, 10 );
			el.classList.toggle( 'sim-step-active',    n === step );
			el.classList.toggle( 'sim-step-completed', n < step );
		} );
	}

	// -------------------------------------------------------------------------
	// Step 1: Select Posts
	// -------------------------------------------------------------------------

	function buildStep1() {
		const root = q( '.sim-bulk-step[data-step="1"]' );
		if ( ! root ) return;

		let typeOptions = '';
		if ( postTypes ) {
			Object.entries( postTypes ).forEach( ( [ slug, label ] ) => {
				typeOptions += '<option value="' + escHtml( slug ) + '">' + escHtml( label ) + '</option>';
			} );
		}

		root.innerHTML = `
			<div class="sim-card-head">
				<div>
					<h2>${ i18n.selectPosts }</h2>
					<p class="description">Choose exactly which posts should be queued for matching.</p>
				</div>
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
					<textarea id="sim-post-refs" rows="3"
						placeholder="Leave blank for filtered results, or paste IDs/slugs separated by commas, spaces, or new lines"></textarea>
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
					<input type="search" id="sim-post-search" placeholder="Title, content, excerpt, or slug contains..." />
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
					<textarea id="sim-taxonomy-filters" rows="2"
						placeholder="category:poultry,biosecurity; post_tag:avian-flu"></textarea>
					<p class="description">Use <code>taxonomy:term-slug,term-slug</code>. Separate multiple taxonomies with semicolons or new lines.</p>
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
					<p class="description">Caps how many posts this job queues.</p>
				</div>
			</div>
			<div class="sim-step-actions">
				<button type="button" class="button button-primary" id="sim-step1-next">
					Next: Configure ${ Icons.chevronRight ? Icons.chevronRight() : '&rarr;' }
				</button>
			</div>`;

		populateSegments();
		q( '#sim-step1-next', root ).addEventListener( 'click', function () {
			showStep( 2 );
		} );
		q( '#sim-save-segment', root ).addEventListener( 'click', saveCurrentSegment );
		q( '#sim-load-segment', root ).addEventListener( 'click', function () {
			const name = q( '#sim-saved-segment' )?.value || '';
			const segments = readSegments();
			if ( name && segments[ name ] ) {
				applySelection( segments[ name ] );
			}
		} );
	}

	// -------------------------------------------------------------------------
	// Step 2: Configure
	// -------------------------------------------------------------------------

	function buildStep2() {
		const root = q( '.sim-bulk-step[data-step="2"]' );
		if ( ! root ) return;

		root.innerHTML = `
			<div class="sim-card-head">
				<div>
					<h2>${ i18n.configure }</h2>
					<p class="description">Choose the matching mode and confidence rules for this run.</p>
				</div>
			</div>
			<div class="sim-form-grid">
				<div class="sim-field">
					<label for="sim-bulk-mode">Matching Mode</label>
					<select id="sim-bulk-mode">
						<option value="keyword">Keyword (fast)</option>
						<option value="ai">AI via Connectors (accurate)</option>
					</select>
				</div>
				<div class="sim-field">
					<label for="sim-bulk-threshold">Minimum Confidence</label>
					<input type="number" id="sim-bulk-threshold" value="70" min="0" max="100" step="5" />
					<p class="description">Only suggest images that score at or above this threshold.</p>
				</div>
			</div>
			<div class="sim-step-actions">
				<button type="button" class="button" id="sim-step2-back">
					${ Icons.chevronLeft ? Icons.chevronLeft() : '&larr;' } Back
				</button>
				<button type="button" class="button button-primary" id="sim-step2-start">
					${ Icons.spinner ? '<span class="sim-icon-wrap">' + Icons.spinner() + '</span>' : '' }
					Find Matches
				</button>
			</div>`;

		q( '#sim-step2-back',  root ).addEventListener( 'click', () => showStep( 1 ) );
		q( '#sim-step2-start', root ).addEventListener( 'click', startJob );
	}

	// -------------------------------------------------------------------------
	// Step 3: Find Matches
	// -------------------------------------------------------------------------

	function buildStep3( jobData ) {
		const root = q( '.sim-bulk-step[data-step="3"]' );
		if ( ! root ) return;

		root.innerHTML = `
			<div class="sim-card-head">
				<div>
					<h2>${ i18n.findMatches }</h2>
					<p class="description">Scans the selected posts and saves suggested image matches for review. Post content is not changed in this step.</p>
				</div>
				<span class="sim-status sim-status-info">Queued</span>
			</div>
			<div class="sim-info-grid">
				<div><span>Job</span><code>${ escHtml( jobData.job_id ) }</code></div>
				<div><span>Post type</span><strong>${ escHtml( getJobPostType( jobData ) || 'post' ) }</strong></div>
				<div><span>Total</span><strong>${ getJobTotal( jobData ) }</strong></div>
			</div>
			<div class="sim-progress-bar">
				<div class="sim-progress-fill" id="sim-bulk-progress" style="width:0%"></div>
			</div>
			<p id="sim-bulk-status-msg">Queued - waiting to find matches&hellip;</p>
			<div id="sim-bulk-activity"></div>
			<div class="sim-step-actions">
				<button type="button" class="button" id="sim-step3-cancel">
					${ Icons.cross ? Icons.cross() : '' } ${ i18n.cancel }
				</button>
			</div>`;

		q( '#sim-step3-cancel', root ).addEventListener( 'click', () => cancelJob( jobData.job_id ) );

		startPolling( jobData.job_id );
	}

	// -------------------------------------------------------------------------
	// Step 4: Review & Insert
	// -------------------------------------------------------------------------

	function buildStep4( jobId ) {
		const root = q( '.sim-bulk-step[data-step="4"]' );
		if ( ! root ) return;

		root.innerHTML = `
			<div class="sim-card-head">
				<div>
					<h2>${ i18n.reviewInsert }</h2>
					<p class="description">Approve the suggested matches you want to use, reject the rest, then insert only the approved images.</p>
				</div>
			</div>
			<div class="sim-review-toolbar">
				<button type="button" class="button" id="sim-approve-all-90">
					${ Icons.check ? Icons.check() : '' } Approve all &ge;90%
				</button>
				<button type="button" class="button" id="sim-reject-all-50">
					${ Icons.cross ? Icons.cross() : '' } Reject all &lt;50%
				</button>
				<button type="button" class="button button-primary" id="sim-insert-approved" style="float:right">
					${ Icons.check ? Icons.check() : '' } ${ i18n.insertApproved }
				</button>
				<button type="button" class="button" id="sim-step4-cancel" style="float:right;margin-right:8px">
					${ Icons.cross ? Icons.cross() : '' } ${ i18n.cancelReview }
				</button>
			</div>
			<div id="sim-review-table-wrap"><p>Loading matches&hellip;</p></div>
			<div id="sim-review-pagination"></div>`;

		q( '#sim-approve-all-90', root ).addEventListener( 'click', () => bulkUpdateMatches( jobId, 'approved', 90 ) );
		q( '#sim-reject-all-50',  root ).addEventListener( 'click', () => bulkUpdateMatches( jobId, 'rejected', 50 ) );
		q( '#sim-insert-approved', root ).addEventListener( 'click', () => insertApproved( jobId ) );
		q( '#sim-step4-cancel', root ).addEventListener( 'click', () => cancelReview( jobId ) );

		loadReviewPage( jobId, 1 );
	}

	async function loadReviewPage( jobId, page ) {
		const wrap = q( '#sim-review-table-wrap' );
		if ( ! wrap ) return;

		try {
			const data = await apiFetch( {
				path: `/smart-image-matcher/v1/jobs/${ jobId }/matches?page=${ page }&per_page=50&status=pending`,
				method: 'GET',
			} );

			if ( ! data.matches || data.matches.length === 0 ) {
				wrap.innerHTML = '<p>' + escHtml( i18n.noMatches ) + '</p>';
				return;
			}

			let html = '<table class="widefat striped"><thead><tr>' +
				'<th></th><th>Post</th><th>Heading</th><th>Image</th><th>Score</th><th>Actions</th>' +
				'</tr></thead><tbody>';

			data.matches.forEach( m => {
				const scoreClass = m.confidence_score >= 90 ? 'sim-confidence-high'
					: m.confidence_score >= 70 ? 'sim-confidence-medium' : 'sim-confidence-low';
				html += `<tr data-match-id="${ m.id }">
					<td><input type="checkbox" class="sim-review-cb" checked /></td>
					<td><a href="/wp-admin/post.php?post=${ m.post_id }&action=edit" target="_blank">${ escHtml( m.post_title || '#' + m.post_id ) }</a></td>
					<td><code>${ escHtml( m.heading_tag ) }</code> ${ escHtml( m.heading_text ) }</td>
					<td><img src="${ escHtml( getSrcFromId( m.image_id ) ) }" style="max-width:80px;max-height:60px" /></td>
					<td class="${ scoreClass }">${ m.confidence_score }%</td>
					<td>
						<button type="button" class="button button-small sim-approve-btn" data-match="${ m.id }">${ Icons.check ? Icons.check() : '' } Approve</button>
						<button type="button" class="button button-small sim-reject-btn"  data-match="${ m.id }">${ Icons.cross ? Icons.cross() : '' } Reject</button>
					</td>
				</tr>`;
			} );

			html += '</tbody></table>';
			wrap.innerHTML = html;

			// Bind row-level approve/reject.
			qAll( '.sim-approve-btn', wrap ).forEach( btn => {
				btn.addEventListener( 'click', () => updateMatch( parseInt( btn.dataset.match, 10 ), 'approved' ) );
			} );
			qAll( '.sim-reject-btn', wrap ).forEach( btn => {
				btn.addEventListener( 'click', () => updateMatch( parseInt( btn.dataset.match, 10 ), 'rejected' ) );
			} );

		} catch ( err ) {
			wrap.innerHTML = '<p style="color:red">Error: ' + escHtml( err.message ) + '</p>';
		}
	}

	function getSrcFromId( imageId ) {
		// REST endpoint for attachment src; WordPress ships this.
		return `/wp-json/wp/v2/media/${ imageId }?_fields=source_url`;
	}

	// -------------------------------------------------------------------------
	// API actions
	// -------------------------------------------------------------------------

	async function startJob() {
		const selection = captureSelection();
		const refs      = splitRefs( selection.postRefs );
		const mode      = q( '#sim-bulk-mode' )?.value || 'keyword';
		const threshold = parseInt( q( '#sim-bulk-threshold' )?.value || '70', 10 );

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
			min_score: threshold,
		};

		if ( refs.ids.length ) {
			body.post_ids = refs.ids;
		}

		if ( refs.slugs.length ) {
			body.post_slugs = refs.slugs;
		}

		try {
			const data = await apiFetch( { path: '/smart-image-matcher/v1/jobs', method: 'POST', data: body } );
			currentJob = data;
			rememberJob( data.job_id );
			showStep( 3 );
			buildStep3( data );
		} catch ( err ) {
			alert( 'Failed to start job: ' + err.message );
		}
	}

	function startPolling( jobId ) {
		clearInterval( pollTimer );
		pollTimer = setInterval( async () => {
			try {
				const data = await apiFetch( { path: `/smart-image-matcher/v1/jobs/${ jobId }`, method: 'GET' } );
				updateProgressUI( data );

				if ( data.status === 'completed' || data.status === 'failed' || data.status === 'cancelled' ) {
					clearInterval( pollTimer );
					if ( data.status === 'completed' ) {
						forgetJob();
						setTimeout( () => { showStep( 4 ); buildStep4( jobId ); }, 500 );
					} else if ( data.status === 'cancelled' ) {
						rememberCancelledJob( jobId );
						forgetJob();
						showStep( 2 );
						showStepNotice( 2, 'Job cancelled. Adjust the settings or find matches again.', 'warning' );
					}
				}
			} catch ( err ) {
				// Tolerate transient network errors during polling.
			}
		}, 2000 );
	}

	function updateProgressUI( jobData ) {
		const total  = getJobTotal( jobData ) || 1;
		const done   = getJobDone( jobData );
		const pct    = Math.round( ( done / total ) * 100 );

		const bar = q( '#sim-bulk-progress' );
		if ( bar ) bar.style.width = pct + '%';

		const msg = q( '#sim-bulk-status-msg' );
		if ( msg ) msg.textContent = `Status: ${ jobData.status } - ${ done } / ${ total } posts scanned`;

		const log = q( '#sim-bulk-activity' );
		if ( log ) {
			const line = document.createElement( 'div' );
			line.textContent = new Date().toLocaleTimeString() + ' - ' + jobData.status + ' (' + pct + '%)';
			log.appendChild( line );
			log.scrollTop = log.scrollHeight;
		}
	}

	async function cancelJob( jobId ) {
		clearInterval( pollTimer );
		try {
			await apiFetch( { path: `/smart-image-matcher/v1/jobs/${ jobId }/cancel`, method: 'POST', data: {} } );
			currentJob = null;
			rememberCancelledJob( jobId );
			forgetJob();
			showStep( 2 );
			showStepNotice( 2, 'Job cancelled. Adjust the settings or find matches again.', 'warning' );
		} catch ( err ) {
			alert( 'Cancel failed: ' + err.message );
		}
	}

	async function updateMatch( matchId, status ) {
		try {
			await apiFetch( {
				path: `/smart-image-matcher/v1/matches/${ matchId }`,
				method: 'POST',
				data: { status },
			} );
			// Remove the row visually.
			const row = q( `tr[data-match-id="${ matchId }"]` );
			if ( row ) row.style.opacity = '0.4';
		} catch ( err ) {
			alert( 'Update failed: ' + err.message );
		}
	}

	async function bulkUpdateMatches( jobId, status, threshold ) {
		// Approve/reject all visible matches above/below threshold by updating
		// each individually. The server-side bulkApproveAboveThreshold is
		// exposed via a dedicated endpoint in a future iteration; for now
		// iterate the loaded rows.
		const rows = qAll( '.sim-approve-btn, .sim-reject-btn' );
		const promises = [];

		qAll( 'tr[data-match-id]' ).forEach( row => {
			const scoreEl = row.querySelector( 'td.sim-confidence-high, td.sim-confidence-medium, td.sim-confidence-low' );
			if ( ! scoreEl ) return;
			const score = parseInt( scoreEl.textContent, 10 );
			const matchId = parseInt( row.dataset.matchId, 10 );

			const shouldUpdate = status === 'approved' ? score >= threshold : score < threshold;
			if ( shouldUpdate ) {
				promises.push( updateMatch( matchId, status ) );
			}
		} );

		await Promise.all( promises );
	}

	async function insertApproved( jobId ) {
		try {
			const data = await apiFetch( {
				path: `/smart-image-matcher/v1/jobs/${ jobId }/insert-approved`,
				method: 'POST',
				data: {},
			} );
			alert( `${ data.queued } post${ data.queued !== 1 ? 's' : '' } queued for insertion.` );
		} catch ( err ) {
			alert( 'Insert failed: ' + err.message );
		}
	}

	async function cancelReview( jobId ) {
		clearInterval( pollTimer );
		try {
			await apiFetch( { path: `/smart-image-matcher/v1/jobs/${ jobId }/cancel`, method: 'POST', data: {} } );
		} catch ( err ) {
			// The review can still be dismissed locally if the server request fails.
		}

		currentJob = null;
		rememberCancelledJob( jobId );
		forgetJob();
		showStep( 1 );
		showStepNotice( 1, 'Review cancelled. Start a new selection when ready.', 'warning' );
	}

	async function resumeJob( jobData, message ) {
		currentJob = jobData;

		if ( ! jobData || isCancelledJobRemembered( jobData.job_id ) || 'cancelled' === jobData.status ) {
			currentJob = null;
			if ( jobData && 'cancelled' === jobData.status ) {
				rememberCancelledJob( jobData.job_id );
			}
			forgetJob();
			showStep( 1 );
			return;
		}

		if ( 'completed' === jobData.status ) {
			currentJob = null;
			forgetJob();
			showStep( 1 );
			return;
		}

		if ( [ 'queued', 'processing', 'inserting' ].includes( jobData.status ) ) {
			rememberJob( jobData.job_id );
			showStep( 3 );
			buildStep3( jobData );
			showStepNotice( 3, message || 'Resumed the active matching job.', 'info' );
			return;
		}

		forgetJob();
	}

	async function resumeLastJob() {
		let storedJobId = '';

		try {
			storedJobId = window.localStorage.getItem( storageKey ) || '';
		} catch ( err ) {}

		if ( storedJobId ) {
			try {
				const job = await apiFetch( { path: `/smart-image-matcher/v1/jobs/${ storedJobId }`, method: 'GET' } );
				await resumeJob( job, 'Resumed the job you were viewing before the page changed.' );
				return;
			} catch ( err ) {
				forgetJob();
			}
		}

		// Do not auto-attach to arbitrary recent jobs. That made cancelled jobs
		// and old queued jobs pull the UI back to Step 3 after refresh.
	}

	// -------------------------------------------------------------------------
	// Init
	// -------------------------------------------------------------------------

	function init() {
		if ( ! apiFetch ) {
			showBootError( 'The WordPress api-fetch script is unavailable on this page.' );
			return;
		}

		if ( ! nonce ) {
			showBootError( 'The REST nonce was not provided for this page.' );
			return;
		}

		if ( ! apiFetch.createNonceMiddleware ) {
			showBootError( 'The WordPress api-fetch nonce middleware is unavailable on this page.' );
			return;
		}

		buildStep1();
		buildStep2();
		showStep( 1 );
		resumeLastJob();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

} )();
