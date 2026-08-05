/**
 * generate-images.js - AI Generate Featured Images (Featured Images admin card).
 *
 * Featured-image only. Heading images stay in the post editor modal.
 *
 * @package SmartImageMatcher
 * @since   3.2.0
 */

( function () {
	'use strict';

	const config = window.smartImageMatcherGenerateImages || {};
	const apiFetch = window.wp && window.wp.apiFetch;
	const nonce = config.nonce || '';
	const prefillPostIds = Array.isArray( config.prefillPostIds ) ? config.prefillPostIds : [];
	const generationReady = !! config.generationReady;
	const editPostUrl = config.editPostUrl || 'post.php?post=%d&action=edit';
	const focusAi = !! config.focusAi;

	const i18n = Object.assign( {
		scanning: 'Scanning posts…',
		scanFailed: 'Could not scan posts.',
		noStatuses: 'Select at least one post status before scanning.',
		noResults: 'No posts need a featured image for the current filters.',
		scanComplete: 'Scan complete.',
		confirmGenerate: 'Generate %1$d featured image(s)? Estimated time: about %2$s.',
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
	}, config.i18n || {} );

	if ( apiFetch && apiFetch.createNonceMiddleware && nonce ) {
		apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );
	}

	let scanResult = null;
	let activeJobs = [];
	let pollTimer = null;
	let isWorking = false;

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
		return String( template ).replace( /%(\d+)\$[ds]/g, ( match, index ) => {
			const value = args[ parseInt( index, 10 ) - 1 ];
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

	function showNotice( type, message ) {
		const notice = q( '#sim-generate-notice' );
		if ( ! notice ) {
			return;
		}
		notice.innerHTML = `<div class="notice notice-${ escHtml( type ) } inline"><p>${ escHtml( message ) }</p></div>`;
	}

	function clearNotice() {
		const notice = q( '#sim-generate-notice' );
		if ( notice ) {
			notice.innerHTML = '';
		}
	}

	function collectFormData() {
		const postType = q( '#sim-generate-post-type' );
		const style = q( '#sim-generate-style' );
		const maxPosts = q( '#sim-generate-max-posts' );
		const statuses = qa( 'input[name="post_statuses[]"]:checked' ).map( input => input.value );

		return {
			post_type: postType ? postType.value : 'post',
			post_statuses: statuses.length ? statuses : [ 'publish' ],
			post_ids: prefillPostIds.length ? prefillPostIds : [],
			max_posts: maxPosts ? parseInt( maxPosts.value || 100, 10 ) : 100,
			style: style ? style.value : 'photo',
		};
	}

	function setWorking( working ) {
		isWorking = working;
		const scanBtn = q( '#sim-generate-scan-button' );
		const genBtn = q( '#sim-generate-all-button' );
		const postType = q( '#sim-generate-post-type' );
		const maxPosts = q( '#sim-generate-max-posts' );
		const style = q( '#sim-generate-style' );

		if ( scanBtn ) {
			scanBtn.disabled = working;
		}
		if ( genBtn ) {
			genBtn.disabled = working || ! scanResult || parseInt( scanResult.total_images || 0, 10 ) <= 0 || ! generationReady;
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
		qa( 'input[name="post_statuses[]"]' ).forEach( input => {
			input.disabled = working;
		} );
	}

	function renderScanResults( result ) {
		const estimate = q( '#sim-generate-estimate' );
		const totalEl = q( '#sim-generate-total-images' );
		const timeEl = q( '#sim-generate-estimate-time' );
		const table = q( '#sim-generate-results-table' );
		const body = q( '#sim-generate-results-body' );

		if ( ! estimate || ! table || ! body ) {
			return;
		}

		const total = parseInt( result.total_images || 0, 10 );
		const posts = Array.isArray( result.posts ) ? result.posts : [];
		const skipped = Array.isArray( result.skipped ) ? result.skipped : [];

		estimate.style.display = 'block';
		if ( totalEl ) {
			totalEl.textContent = String( total );
		}
		if ( timeEl ) {
			timeEl.textContent = formatDuration( result.estimate_seconds || 0 );
		}

		const rows = [];

		posts.forEach( post => {
			const editUrl = post.id ? editPostUrl.replace( '%d', String( post.id ) ) : '#';
			rows.push( `
				<tr>
					<td>${ escHtml( post.title || `#${ post.id }` ) }</td>
					<td>${ escHtml( i18n.needsFeatured ) }</td>
					<td><a href="${ escHtml( editUrl ) }">${ escHtml( i18n.edit ) }</a></td>
				</tr>` );
		} );

		skipped.forEach( item => {
			const editUrl = item.id ? editPostUrl.replace( '%d', String( item.id ) ) : '#';
			rows.push( `
				<tr>
					<td>${ escHtml( item.title || `#${ item.id }` ) }</td>
					<td>${ escHtml( reasonLabel( item.reason ) ) }</td>
					<td><a href="${ escHtml( editUrl ) }">${ escHtml( i18n.edit ) }</a></td>
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

		posts.forEach( post => {
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
		const percentLabel = q( '#sim-generate-progress-percent' );
		const status = q( '#sim-generate-progress-status' );

		if ( ! progress || ! fill || ! status ) {
			return;
		}

		const percent = total > 0 ? Math.min( 100, Math.round( ( done / total ) * 100 ) ) : 0;
		progress.style.display = 'block';
		fill.style.width = `${ percent }%`;
		if ( percentLabel ) {
			percentLabel.textContent = `${ percent }%`;
		}
		status.textContent = statusText || sprintf( i18n.progress, done, total );
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

		let done = 0;
		let failed = 0;
		const stillActive = [];

		for ( const job of activeJobs ) {
			try {
				const status = await apiFetch( {
					path: `/smart-image-matcher/v1/generate-image/status?post_id=${ job.post_id }&heading_hash=${ encodeURIComponent( job.heading_hash ) }`,
					method: 'GET',
				} );

				const state = status.status || 'processing';
				if ( 'completed' === state || 'exists' === state ) {
					++done;
				} else if ( 'failed' === state || 'error' === state ) {
					++done;
					++failed;
				} else {
					stillActive.push( job );
				}
			} catch ( err ) {
				stillActive.push( job );
			}
		}

		activeJobs = stillActive;
		const total = done + activeJobs.length;
		renderProgress( done, total, sprintf( i18n.progress, done, total ) );

		if ( ! activeJobs.length ) {
			setWorking( false );
			const msg = failed > 0
				? `${ i18n.generateComplete } (${ failed } ${ i18n.failed.toLowerCase() })`
				: i18n.generateComplete;
			showNotice( failed > 0 ? 'warning' : 'success', msg );
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
			const skippedCount = Array.isArray( result.skipped ) ? result.skipped.length : 0;
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

		const estimate = formatDuration( scanResult.estimate_seconds || 0 );
		const confirmed = window.confirm( sprintf( i18n.confirmGenerate, total, estimate ) );
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

			if ( queued <= 0 ) {
				showNotice( 'warning', i18n.noResults );
				setWorking( false );
				return;
			}

			renderProgress( 0, queued, sprintf( i18n.progress, 0, queued ) );
			showNotice(
				'info',
				`${ i18n.queued }: ${ queued }${ skipped > 0 ? ` (${ skipped } skipped)` : '' }`
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

		if ( scanBtn ) {
			scanBtn.addEventListener( 'click', scanPosts );
		}
		if ( genBtn ) {
			genBtn.addEventListener( 'click', generateAll );
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
}() );
