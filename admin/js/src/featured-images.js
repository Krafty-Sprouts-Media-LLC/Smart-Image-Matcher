/**
 * featured-images.js - Featured Image Auto-Assigner job monitor.
 *
 * @package SmartImageMatcher
 * @since   3.0.0
 */

( function () {
	'use strict';

	const config = window.smartImageMatcherFiaa || {};
	const apiFetch = window.wp && window.wp.apiFetch;
	const nonce = config.nonce || '';
	const storageKey = 'smartImageMatcherFiaaCurrentJobId';
	const auditStorageKey = 'smartImageMatcherFiaaAuditJobId';
	const i18n = Object.assign( {
		starting: 'Starting Match Runner...',
		running: 'Running...',
		queued: 'Queued',
		processing: 'Processing',
		completed: 'Run complete.',
		cancelled: 'Run cancelled.',
		failed: 'Run failed.',
		progress: 'Processed %1$d of %2$d posts. Matched: %3$d | Skipped: %4$d | Unmatched: %5$d',
		summary: 'Matched: %1$d | Skipped: %2$d | Unmatched: %3$d',
		stalled: 'The job is still queued. Action Scheduler has not picked it up yet.',
		noApi: 'Match Runner controls could not load because wp.apiFetch is unavailable.',
		saving: 'Saving run settings...',
		saved: 'Run settings saved.',
		saveFailed: 'Could not save run settings.',
		noStatuses: 'Select at least one post status before running.',
		auditScanning: 'Scanning featured images...',
		auditScanFailed: 'Could not scan featured images.',
		auditScanSummary: 'Found %1$d unsafe featured image(s) out of %2$d assigned posts (%3$d safe).',
		auditNoneFound: 'No unsafe featured images were found.',
		auditPreviewNote: 'Showing the first %1$d of %2$d unsafe posts.',
		auditStarting: 'Starting cleanup...',
		auditProgress: 'Processed %1$d of %2$d posts. Cleared: %3$d | Skipped: %4$d | Errors: %5$d',
		auditSummary: 'Cleared: %1$d | Skipped: %2$d | Errors: %3$d',
		auditCompleted: 'Cleanup complete.',
		auditClearing: 'Clearing...',
	}, config.i18n || {} );

	if ( apiFetch && apiFetch.createNonceMiddleware && nonce ) {
		apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );
	}

	let pollTimer = null;
	let currentJobId = '';
	let currentJobType = 'fiaa_manual';
	let isSaving = false;
	let shouldScrollToProgress = false;
	let lastAuditScan = null;

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
		return String( template ).replace( /%(\d+)\$d/g, ( match, index ) => {
			const value = args[ parseInt( index, 10 ) - 1 ];
			return parseInt( value || 0, 10 );
		} );
	}

	function buildQueryPath( path, params ) {
		const query = new window.URLSearchParams();

		Object.keys( params ).forEach( key => {
			const value = params[ key ];

			if ( Array.isArray( value ) ) {
				value.forEach( item => query.append( `${ key }[]`, item ) );
				return;
			}

			query.append( key, value );
		} );

		return `${ path }?${ query.toString() }`;
	}

	function rememberJob( jobId, jobType ) {
		const key = 'fiaa_audit_clear' === jobType ? auditStorageKey : storageKey;
		try {
			window.localStorage.setItem( key, jobId );
		} catch ( err ) {}
	}

	function forgetJob( jobType ) {
		const key = 'fiaa_audit_clear' === jobType ? auditStorageKey : storageKey;
		try {
			window.localStorage.removeItem( key );
		} catch ( err ) {}
	}

	function readRememberedJob( jobType ) {
		const key = 'fiaa_audit_clear' === jobType ? auditStorageKey : storageKey;
		try {
			return window.localStorage.getItem( key ) || '';
		} catch ( err ) {
			return '';
		}
	}

	function progressTemplate( jobType ) {
		return 'fiaa_audit_clear' === jobType ? i18n.auditProgress : i18n.progress;
	}

	function summaryTemplate( jobType ) {
		return 'fiaa_audit_clear' === jobType ? i18n.auditSummary : i18n.summary;
	}

	function completedLabel( jobType ) {
		return 'fiaa_audit_clear' === jobType ? i18n.auditCompleted : i18n.completed;
	}

	function noticeSelector( target ) {
		return 'audit' === target ? '#sim-fiaa-audit-notice' : '#sim-fiaa-notice';
	}

	function showNotice( type, message, target ) {
		const notice = q( noticeSelector( target ) );
		if ( ! notice ) {
			return;
		}

		notice.innerHTML = `<div class="notice notice-${ escHtml( type ) } inline"><p>${ escHtml( message ) }</p></div>`;
	}

	function clearNotice( target ) {
		const notice = q( noticeSelector( target ) );
		if ( notice ) {
			notice.innerHTML = '';
		}
	}

	function noticeTargetForJob( jobType ) {
		return 'fiaa_audit_clear' === jobType ? 'audit' : 'runner';
	}

	function collectFormSettings() {
		const postType = q( '#smart_image_matcher_fiaa_post_type' );
		const selectedStatuses = qa( 'input[name="smart_image_matcher_fiaa_post_statuses[]"]:checked' ).map( input => input.value );
		const featuredFilter = q( '#smart_image_matcher_fiaa_featured_filter' );
		const maxPosts = q( '#smart_image_matcher_fiaa_max_posts' );
		const overwrite = q( 'input[name="smart_image_matcher_fiaa_overwrite"]' );
		const shouldOverwrite = overwrite ? !! overwrite.checked : false;
		const requestedFeaturedFilter = featuredFilter ? featuredFilter.value : 'missing';

		return {
			post_type: postType ? postType.value : 'post',
			post_statuses: selectedStatuses.length ? selectedStatuses : [ 'publish' ],
			featured_filter: shouldOverwrite ? 'any' : requestedFeaturedFilter,
			max_posts: maxPosts ? parseInt( maxPosts.value || 5000, 10 ) : 5000,
			overwrite: shouldOverwrite,
		};
	}

	function applySavedSettings( settings ) {
		if ( ! settings || 'object' !== typeof settings ) {
			return;
		}

		const postType = q( '#smart_image_matcher_fiaa_post_type' );
		const featuredFilter = q( '#smart_image_matcher_fiaa_featured_filter' );
		const maxPosts = q( '#smart_image_matcher_fiaa_max_posts' );
		const overwrite = q( 'input[name="smart_image_matcher_fiaa_overwrite"]' );
		const statuses = Array.isArray( settings.post_statuses ) ? settings.post_statuses : [];

		if ( postType && settings.post_type ) {
			postType.value = settings.post_type;
		}

		qa( 'input[name="smart_image_matcher_fiaa_post_statuses[]"]' ).forEach( input => {
			input.checked = statuses.includes( input.value );
		} );

		if ( featuredFilter && settings.featured_filter ) {
			featuredFilter.value = settings.featured_filter;
		}

		if ( maxPosts && settings.max_posts ) {
			maxPosts.value = String( settings.max_posts );
		}

		if ( overwrite ) {
			overwrite.checked = !! settings.overwrite;
			if ( overwrite.checked && featuredFilter ) {
				featuredFilter.value = 'any';
			}
		}
	}

	function setWorking( working, jobType ) {
		const activeType = jobType || currentJobType || 'fiaa_manual';
		const runButton = q( '#sim-fiaa-run-button' );
		const saveButton = q( '#sim-fiaa-save-button' );
		const postType = q( '#smart_image_matcher_fiaa_post_type' );
		const postStatuses = qa( 'input[name="smart_image_matcher_fiaa_post_statuses[]"]' );
		const featuredFilter = q( '#smart_image_matcher_fiaa_featured_filter' );
		const maxPosts = q( '#smart_image_matcher_fiaa_max_posts' );
		const overwrite = q( 'input[name="smart_image_matcher_fiaa_overwrite"]' );
		const cancelButton = q( '#sim-fiaa-cancel-button' );
		const progress = q( '#sim-fiaa-progress' );
		const auditScanButton = q( '#sim-fiaa-audit-scan-button' );
		const auditClearButton = q( '#sim-fiaa-audit-clear-button' );
		const defaultLabel = runButton ? ( runButton.dataset.defaultLabel || runButton.textContent.trim() ) : '';
		const auditClearDefault = auditClearButton ? ( auditClearButton.dataset.defaultLabel || auditClearButton.textContent.trim() ) : '';
		const isAuditJob = 'fiaa_audit_clear' === activeType;

		if ( runButton ) {
			runButton.disabled = working || isSaving || ( working && isAuditJob );
			runButton.textContent = ( working && ! isAuditJob ) ? i18n.running : defaultLabel;
			runButton.classList.toggle( 'sim-is-running', working && ! isAuditJob );
		}
		if ( saveButton ) {
			saveButton.disabled = working || isSaving;
		}
		if ( postType ) {
			postType.disabled = working;
		}
		postStatuses.forEach( input => {
			input.disabled = working;
		} );
		if ( featuredFilter ) {
			featuredFilter.disabled = working;
		}
		if ( maxPosts ) {
			maxPosts.disabled = working;
		}
		if ( overwrite ) {
			overwrite.disabled = working;
		}
		if ( cancelButton ) {
			cancelButton.disabled = ! working;
		}
		if ( auditScanButton ) {
			auditScanButton.disabled = working;
		}
		if ( auditClearButton ) {
			const canClear = lastAuditScan && parseInt( lastAuditScan.unsafe || 0, 10 ) > 0;
			auditClearButton.disabled = working || ! canClear;
			auditClearButton.textContent = ( working && isAuditJob ) ? i18n.auditClearing : auditClearDefault;
		}
		if ( progress ) {
			progress.classList.toggle( 'is-active', working );
		}
	}

	function scrollToProgress() {
		const progress = q( '#sim-fiaa-progress' );
		if ( progress && 'function' === typeof progress.scrollIntoView ) {
			progress.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
		}
	}

	function renderJob( job ) {
		const progress = q( '#sim-fiaa-progress' );
		const fill = q( '#sim-fiaa-progress-fill' );
		const percentLabel = q( '#sim-fiaa-progress-percent' );
		const status = q( '#sim-fiaa-status' );
		const table = q( '#sim-fiaa-recent-table' );
		const body = q( '#sim-fiaa-recent-body' );

		if ( ! progress || ! fill || ! status || ! table || ! body ) {
			return;
		}

		const total = parseInt( job.total || 0, 10 );
		const done = parseInt( job.done || 0, 10 );
		const matched = parseInt( job.matched || 0, 10 );
		const skipped = parseInt( job.skipped || 0, 10 );
		const unmatched = parseInt( job.unmatched || 0, 10 );
		const percent = total > 0 ? Math.min( 100, Math.round( ( done / total ) * 100 ) ) : 0;
		const state = job.status || 'queued';
		const isActive = 'completed' !== state && 'cancelled' !== state && 'failed' !== state;
		const jobType = job.job_type || currentJobType || 'fiaa_manual';

		progress.style.display = 'block';
		progress.classList.toggle( 'is-active', isActive );
		fill.style.width = `${ percent }%`;
		if ( percentLabel ) {
			percentLabel.textContent = `${ percent }%`;
		}
		status.textContent = `${ stateLabel( state ) } - ${ sprintf( progressTemplate( jobType ), done, total, matched, skipped, unmatched ) }`;

		if ( 'queued' === state && isPastDue( job.created_at ) ) {
			status.textContent += ` ${ i18n.stalled }`;
		}

		if ( shouldScrollToProgress ) {
			shouldScrollToProgress = false;
			scrollToProgress();
		}

		const recent = Array.isArray( job.recent ) ? job.recent.slice().reverse() : [];
		if ( recent.length ) {
			table.style.display = '';
			body.innerHTML = recent.slice( 0, 12 ).map( item => {
				const title = item.title || `#${ item.id || '' }`;
				const image = item.image_slug
					? `${ item.image_slug } (${ parseInt( item.score || 0, 10 ) }%)`
					: '';

				return `
					<tr>
						<td>${ escHtml( title ) }</td>
						<td><code>${ escHtml( item.slug || '' ) }</code></td>
						<td>${ escHtml( item.status || '' ) }</td>
						<td>${ escHtml( image ) }</td>
					</tr>`;
			} ).join( '' );
		} else {
			table.style.display = 'none';
			body.innerHTML = '';
		}
	}

	function stateLabel( state ) {
		if ( 'processing' === state ) {
			return i18n.processing;
		}
		if ( 'completed' === state ) {
			return i18n.completed;
		}
		if ( 'cancelled' === state ) {
			return i18n.cancelled;
		}
		if ( 'failed' === state ) {
			return i18n.failed;
		}
		return i18n.queued;
	}

	function isPastDue( mysqlTime ) {
		if ( ! mysqlTime ) {
			return false;
		}

		const normalized = String( mysqlTime ).replace( ' ', 'T' );
		const created = new Date( normalized );

		if ( Number.isNaN( created.getTime() ) ) {
			return false;
		}

		return ( Date.now() - created.getTime() ) > 45000;
	}

	function stopPolling() {
		if ( pollTimer ) {
			window.clearTimeout( pollTimer );
			pollTimer = null;
		}
	}

	async function saveSettings( options = {} ) {
		const silent = !! options.silent;
		const settings = collectFormSettings();

		if ( ! settings.post_statuses.length ) {
			if ( ! silent ) {
				showNotice( 'error', i18n.noStatuses );
			}
			return false;
		}

		isSaving = true;
		setWorking( false, 'fiaa_manual' );

		if ( ! silent ) {
			showNotice( 'info', i18n.saving );
		}

		try {
			const saved = await apiFetch( {
				path: '/smart-image-matcher/v1/featured-image-manual-settings',
				method: 'POST',
				data: settings,
			} );

			applySavedSettings( saved );
			if ( ! silent ) {
				showNotice( 'success', i18n.saved );
			}
			return true;
		} catch ( err ) {
			if ( ! silent ) {
				showNotice( 'error', err.message || i18n.saveFailed );
			}
			return false;
		} finally {
			isSaving = false;
			setWorking( false, 'fiaa_manual' );
		}
	}

	function renderAuditResults( result ) {
		const summary = q( '#sim-fiaa-audit-summary' );
		const table = q( '#sim-fiaa-audit-table' );
		const body = q( '#sim-fiaa-audit-body' );
		const previewNote = q( '#sim-fiaa-audit-preview-note' );
		const totalAssigned = q( '#sim-fiaa-audit-total-assigned' );
		const safeCount = q( '#sim-fiaa-audit-safe' );
		const unsafeCount = q( '#sim-fiaa-audit-unsafe' );
		const clearButton = q( '#sim-fiaa-audit-clear-button' );

		if ( ! summary || ! table || ! body ) {
			return;
		}

		const total = parseInt( result.total_assigned || 0, 10 );
		const safe = parseInt( result.safe || 0, 10 );
		const unsafe = parseInt( result.unsafe || 0, 10 );
		const preview = Array.isArray( result.preview ) ? result.preview : [];

		summary.style.display = 'block';
		if ( totalAssigned ) {
			totalAssigned.textContent = String( total );
		}
		if ( safeCount ) {
			safeCount.textContent = String( safe );
		}
		if ( unsafeCount ) {
			unsafeCount.textContent = String( unsafe );
		}

		if ( preview.length ) {
			table.style.display = '';
			body.innerHTML = preview.map( item => `
				<tr>
					<td>${ escHtml( item.title || `#${ item.id || '' }` ) }</td>
					<td><code>${ escHtml( item.post_slug || '' ) }</code></td>
					<td><code>${ escHtml( item.image_slug || '' ) }</code></td>
					<td>${ escHtml( item.method || '' ) }</td>
					<td>${ escHtml( String( parseInt( item.score || 0, 10 ) ) ) }%</td>
				</tr>
			` ).join( '' );
		} else {
			table.style.display = 'none';
			body.innerHTML = '';
		}

		if ( previewNote ) {
			if ( unsafe > preview.length && preview.length > 0 ) {
				previewNote.style.display = '';
				previewNote.textContent = sprintf( i18n.auditPreviewNote, preview.length, unsafe );
			} else {
				previewNote.style.display = 'none';
				previewNote.textContent = '';
			}
		}

		if ( clearButton ) {
			clearButton.disabled = unsafe <= 0;
		}
	}

	async function scanAudit() {
		const settings = collectFormSettings();

		if ( ! settings.post_statuses.length ) {
			showNotice( 'error', i18n.noStatuses, 'audit' );
			return;
		}

		clearNotice( 'audit' );
		showNotice( 'info', i18n.auditScanning, 'audit' );

		try {
			const result = await apiFetch( {
				path: buildQueryPath( '/smart-image-matcher/v1/featured-image-audit', {
					post_type: settings.post_type,
					post_statuses: settings.post_statuses,
				} ),
				method: 'GET',
			} );

			lastAuditScan = result;
			renderAuditResults( result );

			const unsafe = parseInt( result.unsafe || 0, 10 );
			const total = parseInt( result.total_assigned || 0, 10 );
			const safe = parseInt( result.safe || 0, 10 );

			if ( unsafe > 0 ) {
				showNotice( 'warning', sprintf( i18n.auditScanSummary, unsafe, total, safe ), 'audit' );
			} else {
				showNotice( 'success', i18n.auditNoneFound, 'audit' );
			}
		} catch ( err ) {
			showNotice( 'error', err.message || i18n.auditScanFailed, 'audit' );
		}
	}

	async function pollJob( jobId, jobType ) {
		currentJobId = jobId;
		currentJobType = jobType || currentJobType || 'fiaa_manual';

		try {
			const job = await apiFetch( { path: `/smart-image-matcher/v1/featured-image-jobs/${ jobId }`, method: 'GET' } );
			currentJobType = job.job_type || currentJobType;
			renderJob( job );

			if ( 'completed' === job.status ) {
				setWorking( false, currentJobType );
				forgetJob( currentJobType );
				showNotice(
					'success',
					`${ completedLabel( currentJobType ) } ${ sprintf( summaryTemplate( currentJobType ), job.matched, job.skipped, job.unmatched ) }`,
					noticeTargetForJob( currentJobType )
				);
				if ( 'fiaa_audit_clear' === currentJobType ) {
					lastAuditScan = { total_assigned: 0, safe: 0, unsafe: 0, preview: [] };
					renderAuditResults( lastAuditScan );
				}
				return;
			}

			if ( 'cancelled' === job.status ) {
				setWorking( false, currentJobType );
				forgetJob( currentJobType );
				showNotice( 'warning', i18n.cancelled, noticeTargetForJob( currentJobType ) );
				return;
			}

			if ( 'failed' === job.status ) {
				setWorking( false, currentJobType );
				forgetJob( currentJobType );
				showNotice( 'error', job.error_message || i18n.failed, noticeTargetForJob( currentJobType ) );
				return;
			}

			setWorking( true, currentJobType );
			rememberJob( jobId, currentJobType );
			pollTimer = window.setTimeout( () => pollJob( jobId, currentJobType ), 2000 );
		} catch ( err ) {
			setWorking( false, currentJobType );
			showNotice( 'error', err.message || i18n.failed, noticeTargetForJob( currentJobType ) );
		}
	}

	async function startJob() {
		const settings = collectFormSettings();

		if ( ! settings.post_statuses.length ) {
			showNotice( 'error', i18n.noStatuses );
			return;
		}

		clearNotice( 'runner' );
		stopPolling();
		currentJobType = 'fiaa_manual';
		setWorking( true, currentJobType );
		shouldScrollToProgress = true;
		showNotice( 'info', i18n.starting, 'runner' );

		try {
			await saveSettings( { silent: true } );

			const job = await apiFetch( {
				path: '/smart-image-matcher/v1/featured-image-jobs',
				method: 'POST',
				data: settings,
			} );

			currentJobId = job.job_id || '';
			if ( currentJobId ) {
				rememberJob( currentJobId, currentJobType );
				renderJob( job );
				pollJob( currentJobId, currentJobType );
			}
		} catch ( err ) {
			setWorking( false, currentJobType );
			showNotice( 'error', err.message || i18n.failed, 'runner' );
		}
	}

	async function startAuditClear() {
		const settings = collectFormSettings();

		if ( ! settings.post_statuses.length ) {
			showNotice( 'error', i18n.noStatuses, 'audit' );
			return;
		}

		if ( ! lastAuditScan || parseInt( lastAuditScan.unsafe || 0, 10 ) <= 0 ) {
			showNotice( 'error', i18n.auditNoneFound, 'audit' );
			return;
		}

		clearNotice( 'audit' );
		stopPolling();
		currentJobType = 'fiaa_audit_clear';
		setWorking( true, currentJobType );
		shouldScrollToProgress = true;
		showNotice( 'info', i18n.auditStarting, 'audit' );

		try {
			const job = await apiFetch( {
				path: '/smart-image-matcher/v1/featured-image-audit/clear',
				method: 'POST',
				data: {
					post_type: settings.post_type,
					post_statuses: settings.post_statuses,
				},
			} );

			if ( ! job.job_id ) {
				setWorking( false, currentJobType );
				showNotice( 'success', job.message || i18n.auditNoneFound, 'audit' );
				lastAuditScan = { total_assigned: 0, safe: 0, unsafe: 0, preview: [] };
				renderAuditResults( lastAuditScan );
				return;
			}

			currentJobId = job.job_id;
			rememberJob( currentJobId, currentJobType );
			renderJob( job );
			pollJob( currentJobId, currentJobType );
		} catch ( err ) {
			setWorking( false, currentJobType );
			showNotice( 'error', err.message || i18n.failed, 'audit' );
		}
	}

	async function cancelJob() {
		if ( ! currentJobId ) {
			return;
		}

		stopPolling();
		setWorking( true, currentJobType );

		try {
			const job = await apiFetch( {
				path: `/smart-image-matcher/v1/featured-image-jobs/${ currentJobId }/cancel`,
				method: 'POST',
				data: {},
			} );
			renderJob( job );
			forgetJob( currentJobType );
			setWorking( false, currentJobType );
			showNotice( 'warning', i18n.cancelled, noticeTargetForJob( currentJobType ) );
		} catch ( err ) {
			setWorking( false, currentJobType );
			showNotice( 'error', err.message || i18n.failed, noticeTargetForJob( currentJobType ) );
		}
	}

	function boot() {
		if ( ! q( '#sim-fiaa-runner' ) ) {
			return;
		}

		if ( ! apiFetch ) {
			showNotice( 'error', i18n.noApi );
			return;
		}

		applySavedSettings( config.savedSettings || {} );

		const runButton = q( '#sim-fiaa-run-button' );
		const saveButton = q( '#sim-fiaa-save-button' );
		const cancelButton = q( '#sim-fiaa-cancel-button' );
		const auditScanButton = q( '#sim-fiaa-audit-scan-button' );
		const auditClearButton = q( '#sim-fiaa-audit-clear-button' );

		if ( auditClearButton ) {
			auditClearButton.dataset.defaultLabel = auditClearButton.textContent.trim();
		}

		if ( runButton ) {
			runButton.addEventListener( 'click', startJob );
		}
		if ( saveButton ) {
			saveButton.addEventListener( 'click', () => saveSettings() );
		}
		if ( cancelButton ) {
			cancelButton.addEventListener( 'click', cancelJob );
		}
		if ( auditScanButton ) {
			auditScanButton.addEventListener( 'click', scanAudit );
		}
		if ( auditClearButton ) {
			auditClearButton.addEventListener( 'click', startAuditClear );
		}

		const overwrite = q( 'input[name="smart_image_matcher_fiaa_overwrite"]' );
		const featuredFilter = q( '#smart_image_matcher_fiaa_featured_filter' );

		if ( overwrite && featuredFilter ) {
			overwrite.addEventListener( 'change', () => {
				if ( overwrite.checked ) {
					featuredFilter.value = 'any';
				}
			} );
		}

		const auditJobId = readRememberedJob( 'fiaa_audit_clear' );
		const runJobId = readRememberedJob( 'fiaa_manual' );

		if ( auditJobId ) {
			shouldScrollToProgress = true;
			pollJob( auditJobId, 'fiaa_audit_clear' );
		} else if ( runJobId ) {
			shouldScrollToProgress = true;
			pollJob( runJobId, 'fiaa_manual' );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
