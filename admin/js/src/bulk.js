/**
 * bulk.js — Bulk Processor (Premium): Run | Review | Schedule | Tools.
 *
 * Run: mode → which articles → options, with a live count in the summary rail.
 * Review: one row per article; decide suggestions with ✓ / ✕ (remembered for
 * that heading only). Blocking an image site-wide sits behind ⋯ and an
 * explained confirmation — it has no keyboard shortcut.
 * Schedule: automatic-run settings, open runs (close a stuck one), every tick.
 *
 * @package SmartImageMatcher
 * @since   3.0.0 (rewritten 3.7.0)
 */

( function () {
	'use strict';

	const app = document.getElementById( 'sim-bulk-app' );
	if ( ! app ) {
		return;
	}

	const config = window.smartImageMatcherBulk || {};
	const apiFetch = window.wp && window.wp.apiFetch;
	const wpI18n = ( window.wp && window.wp.i18n ) || {};
	const __ = wpI18n.__ || ( ( s ) => s );
	const _n = wpI18n._n || ( ( one, many, n ) => ( n === 1 ? one : many ) );
	const sprintf = wpI18n.sprintf || ( ( s, ...a ) => {
		let i = 0;
		return String( s ).replace( /%(\d+\$)?[sd]/g, () => String( a[ i++ ] ) );
	} );

	const API = '/smart-image-matcher/v1';
	const postTypes = config.postTypes && Object.keys( config.postTypes ).length ? config.postTypes : { post: 'Posts', page: 'Pages' };
	const AUTO = parseInt( config.autoInsertThreshold || '90', 10 );
	const REVIEW_MIN = parseInt( config.reviewThreshold || '70', 10 );
	const MID = Math.min( 80, AUTO );
	const generationAvailable = !! config.generationAvailable;
	const PER_PAGE = 20;

	const KEY_JOB = 'smartImageMatcherBulkCurrentJobId';
	const KEY_CANCELLED = 'smartImageMatcherBulkCancelledJobIds';
	const KEY_SEGMENTS = 'smartImageMatcherBulkSelectionSegments';

	if ( apiFetch && apiFetch.createNonceMiddleware && config.nonce ) {
		apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );
	}

	const state = {
		tab: app.dataset.simTab || 'run',
		job: null,
		pollTimer: null,
		pace: null, // { t, done } first sample of the current poll session.
		review: {
			search: '',
			slot: 'all',
			band: 'all',
			run: app.dataset.simRun === 'last' ? 'last' : 'all',
			page: 1,
			articles: [],
			totalArticles: 0,
			totalSuggestions: 0,
			open: new Set(),
			selected: new Set(),
			decided: new Map(), // match id → approved | rejected
			focus: -1,
			loadedOnce: false,
		},
		tallies: { approved: 0, rejected: 0 },
		modal: { postId: 0, index: 0 },
		countTimer: null,
		countSeq: 0,
		lastCount: null,
	};

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	function q( sel, root ) {
		return ( root || document ).querySelector( sel );
	}

	function qAll( sel, root ) {
		return Array.from( ( root || document ).querySelectorAll( sel ) );
	}

	// Safe in text and in quoted attributes (titles and filenames can contain quotes).
	function esc( value ) {
		return String( value == null ? '' : value )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}

	function api( path, method, data ) {
		const opts = { path: API + path, method: method || 'GET' };
		if ( data !== undefined ) {
			opts.data = data;
		}
		return apiFetch( opts );
	}

	function store( key, value ) {
		try {
			if ( value === null ) {
				window.localStorage.removeItem( key );
			} else {
				window.localStorage.setItem( key, typeof value === 'string' ? value : JSON.stringify( value ) );
			}
		} catch ( e ) {}
	}

	function read( key, fallback ) {
		try {
			const raw = window.localStorage.getItem( key );
			if ( raw === null ) return fallback;
			try {
				return JSON.parse( raw );
			} catch ( e ) {
				return raw;
			}
		} catch ( e ) {
			return fallback;
		}
	}

	function parseTotals( job ) {
		if ( job && typeof job.totals === 'string' && job.totals ) {
			try {
				const t = JSON.parse( job.totals );
				return t && typeof t === 'object' ? t : {};
			} catch ( e ) {
				return {};
			}
		}
		return job && job.totals && typeof job.totals === 'object' ? job.totals : {};
	}

	function num( job, key ) {
		const t = parseTotals( job );
		return parseInt( ( job && job[ key ] ) || t[ key ] || 0, 10 );
	}

	function jobConfig( job ) {
		return ( job && job.config ) || parseTotals( job ).config || {};
	}

	function isRunning( job ) {
		return !! job && ( job.status === 'queued' || job.status === 'processing' );
	}

	function errMsg( err, fallback ) {
		return ( err && err.message ) ? err.message : fallback;
	}

	function minutesLabel( mins ) {
		if ( mins < 1 ) return __( 'under a minute', 'smart-image-matcher' );
		if ( mins < 60 ) return sprintf( _n( '%d min', '%d min', mins, 'smart-image-matcher' ), mins );
		const h = Math.floor( mins / 60 );
		const m = mins % 60;
		return m ? sprintf( __( '%1$d h %2$d min', 'smart-image-matcher' ), h, m ) : sprintf( _n( '%d hour', '%d hours', h, 'smart-image-matcher' ), h );
	}

	function timeLabel( unix ) {
		if ( ! unix ) return '—';
		const d = new Date( unix * 1000 );
		const today = new Date();
		const sameDay = d.toDateString() === today.toDateString();
		const time = d.toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } );
		return sameDay ? time : d.toLocaleDateString( [], { day: 'numeric', month: 'short' } ) + ' ' + time;
	}

	// ---------------------------------------------------------------------
	// Toast
	// ---------------------------------------------------------------------

	let toastTimer = null;

	function toast( message, undo ) {
		const el = q( '#sim-bp-toast' );
		if ( ! el ) return;
		const span = el.querySelector( 'span' );
		const btn = el.querySelector( 'button' );
		span.textContent = message;
		btn.hidden = ! undo;
		btn.onclick = undo ? () => {
			el.hidden = true;
			undo();
		} : null;
		el.hidden = false;
		window.clearTimeout( toastTimer );
		toastTimer = window.setTimeout( () => {
			el.hidden = true;
		}, undo ? 8000 : 4000 );
	}

	// ---------------------------------------------------------------------
	// Tabs
	// ---------------------------------------------------------------------

	function setTab( tab ) {
		state.tab = [ 'run', 'review', 'schedule', 'tools' ].includes( tab ) ? tab : 'run';
		qAll( '.sim-bp-tab' ).forEach( ( el ) => {
			const on = el.dataset.tab === state.tab;
			el.classList.toggle( 'is-on', on );
			el.setAttribute( 'aria-selected', on ? 'true' : 'false' );
		} );
		qAll( '.sim-bulk-panel' ).forEach( ( el ) => {
			el.hidden = el.dataset.panel !== state.tab;
		} );
		const params = new URLSearchParams( window.location.search );
		params.set( 'page', 'smart-image-matcher-bulk' );
		params.set( 'sim_tab', state.tab );
		if ( state.tab === 'review' ) {
			params.set( 'sim_run', state.review.run );
		} else {
			params.delete( 'sim_run' );
		}
		window.history.replaceState( {}, '', window.location.pathname + '?' + params.toString() );

		q( '#sim-bp-dock' ).hidden = state.tab !== 'review';
		if ( state.tab === 'review' ) {
			loadReview();
		} else if ( state.tab === 'schedule' ) {
			loadSchedule();
		} else if ( state.tab === 'tools' ) {
			loadBlocked();
			loadIgnored();
		}
	}

	// ---------------------------------------------------------------------
	// Run tab
	// ---------------------------------------------------------------------

	function runHtml() {
		const typeOptions = Object.entries( postTypes ).map( ( [ slug, label ] ) => `<option value="${ esc( slug ) }">${ esc( label ) }</option>` ).join( '' );
		const statuses = [
			[ 'publish', __( 'Published', 'smart-image-matcher' ), false ],
			[ 'draft', __( 'Draft', 'smart-image-matcher' ), true ],
			[ 'future', __( 'Scheduled', 'smart-image-matcher' ), false ],
			[ 'pending', __( 'Pending', 'smart-image-matcher' ), false ],
			[ 'private', __( 'Private', 'smart-image-matcher' ), false ],
		].map( ( [ v, l, on ] ) => `<label class="sim-bp-chip"><input type="checkbox" class="sim-post-status" value="${ v }"${ on ? ' checked' : '' } />${ esc( l ) }</label>` ).join( '' );

		return `
		<div class="sim-bp-run-grid">
			<form class="sim-bp-card" id="sim-run-form" autocomplete="off">
				<div class="sim-bp-section">
					<div class="sim-bp-section-head"><h2><span class="sim-bp-step">${ esc( __( 'Step 1', 'smart-image-matcher' ) ) }</span>${ esc( __( 'What should happen?', 'smart-image-matcher' ) ) }</h2></div>
					<div class="sim-bp-modes" role="radiogroup" aria-label="${ esc( __( 'Mode', 'smart-image-matcher' ) ) }">
						<label class="sim-bp-mode">
							<input type="radio" name="sim-run-mode" value="process" checked />
							<strong>${ esc( __( 'Match from the library', 'smart-image-matcher' ) ) }</strong>
							<span>${ esc( __( 'Insert strong matches, send uncertain ones to Review.', 'smart-image-matcher' ) ) }</span>
						</label>
						<label class="sim-bp-mode${ generationAvailable ? '' : ' is-disabled' }">
							<input type="radio" name="sim-run-mode" value="generate-featured"${ generationAvailable ? '' : ' disabled' } />
							<strong>${ esc( __( 'Generate featured images', 'smart-image-matcher' ) ) }</strong>
							<span>${ esc( __( 'Create a new featured image for posts that have none. Headings are not touched.', 'smart-image-matcher' ) ) }</span>
							${ generationAvailable
								? `<span class="sim-bp-req">${ esc( __( 'Uses paid image credits · you confirm the count first', 'smart-image-matcher' ) ) }</span>`
								: `<span class="sim-bp-req">${ esc( __( 'Turn on image generation in Settings and connect an image provider.', 'smart-image-matcher' ) ) }</span>` }
						</label>
					</div>
				</div>

				<div class="sim-bp-section">
					<div class="sim-bp-section-head">
						<h2><span class="sim-bp-step">${ esc( __( 'Step 2', 'smart-image-matcher' ) ) }</span>${ esc( __( 'Which articles?', 'smart-image-matcher' ) ) }</h2>
						<div class="sim-bp-inline">
							<label class="screen-reader-text" for="sim-saved-segment">${ esc( __( 'Saved selection', 'smart-image-matcher' ) ) }</label>
							<select id="sim-saved-segment"></select>
							<button type="button" class="button button-link" id="sim-save-segment">${ esc( __( 'Save current', 'smart-image-matcher' ) ) }</button>
							<button type="button" class="button button-link sim-bp-danger-link" id="sim-delete-segment" hidden>${ esc( __( 'Delete', 'smart-image-matcher' ) ) }</button>
						</div>
					</div>
					<div class="sim-bp-fields">
						<div class="sim-bp-field">
							<span class="sim-bp-label" id="sim-lbl-status">${ esc( __( 'Post status', 'smart-image-matcher' ) ) }</span>
							<div class="sim-bp-chips" role="group" aria-labelledby="sim-lbl-status">${ statuses }</div>
						</div>
						<div class="sim-bp-field">
							<span class="sim-bp-label" id="sim-lbl-feat">${ esc( __( 'Featured image', 'smart-image-matcher' ) ) }</span>
							<div class="sim-bp-seg" role="radiogroup" aria-labelledby="sim-lbl-feat">
								<label><input type="radio" name="sim-featured-filter" value="any" checked />${ esc( __( 'Any', 'smart-image-matcher' ) ) }</label>
								<label><input type="radio" name="sim-featured-filter" value="missing" />${ esc( __( 'Missing', 'smart-image-matcher' ) ) }</label>
								<label><input type="radio" name="sim-featured-filter" value="has" />${ esc( __( 'Has one', 'smart-image-matcher' ) ) }</label>
							</div>
						</div>
						<div class="sim-bp-field">
							<label class="sim-bp-label" for="sim-post-type">${ esc( __( 'Post type', 'smart-image-matcher' ) ) }</label>
							<select id="sim-post-type">${ typeOptions }</select>
						</div>
						<div class="sim-bp-field">
							<label class="sim-bp-label" for="sim-post-search">${ esc( __( 'Title, content or slug contains', 'smart-image-matcher' ) ) }</label>
							<input type="search" id="sim-post-search" placeholder="${ esc( __( 'e.g. hobby farm', 'smart-image-matcher' ) ) }" />
						</div>
						<div class="sim-bp-field sim-bp-full">
							<details class="sim-bp-more" id="sim-more-filters">
								<summary>${ esc( __( 'More filters', 'smart-image-matcher' ) ) } <span class="sim-bp-active-filters" id="sim-active-filters"></span></summary>
								<div class="sim-bp-more-inner sim-bp-fields">
									<div class="sim-bp-field sim-bp-full">
										<label class="sim-bp-label" for="sim-post-refs">${ esc( __( 'Only these articles', 'smart-image-matcher' ) ) }</label>
										<textarea id="sim-post-refs" rows="3" placeholder="${ esc( config.samplePostRefs || '123, 456, sample-post-slug' ) }"></textarea>
										<span class="sim-bp-help">${ esc( __( 'IDs or slugs, comma or line separated. Post type and status still apply.', 'smart-image-matcher' ) ) }</span>
									</div>
									<div class="sim-bp-field">
										<label class="sim-bp-label" for="sim-taxonomy-filters">${ esc( __( 'Category or tag', 'smart-image-matcher' ) ) }</label>
										<input type="text" id="sim-taxonomy-filters" placeholder="category:poultry; post_tag:avian-flu" />
									</div>
									<div class="sim-bp-field">
										<label class="sim-bp-label" for="sim-content-filter">${ esc( __( 'Content', 'smart-image-matcher' ) ) }</label>
										<select id="sim-content-filter">
											<option value="any">${ esc( __( 'Any', 'smart-image-matcher' ) ) }</option>
											<option value="has_headings">${ esc( __( 'Has headings', 'smart-image-matcher' ) ) }</option>
											<option value="no_images">${ esc( __( 'No images in the content', 'smart-image-matcher' ) ) }</option>
											<option value="not_processed">${ esc( __( 'Never processed by this plugin', 'smart-image-matcher' ) ) }</option>
										</select>
									</div>
									<div class="sim-bp-field">
										<span class="sim-bp-label">${ esc( __( 'Published between', 'smart-image-matcher' ) ) }</span>
										<div class="sim-bp-inline">
											<input type="date" id="sim-date-after" aria-label="${ esc( __( 'Published after', 'smart-image-matcher' ) ) }" />
											<span class="sim-bp-help">${ esc( __( 'to', 'smart-image-matcher' ) ) }</span>
											<input type="date" id="sim-date-before" aria-label="${ esc( __( 'Published before', 'smart-image-matcher' ) ) }" />
										</div>
									</div>
									<div class="sim-bp-field">
										<span class="sim-bp-label">${ esc( __( 'Modified between', 'smart-image-matcher' ) ) }</span>
										<div class="sim-bp-inline">
											<input type="date" id="sim-modified-after" aria-label="${ esc( __( 'Modified after', 'smart-image-matcher' ) ) }" />
											<span class="sim-bp-help">${ esc( __( 'to', 'smart-image-matcher' ) ) }</span>
											<input type="date" id="sim-modified-before" aria-label="${ esc( __( 'Modified before', 'smart-image-matcher' ) ) }" />
										</div>
									</div>
									<div class="sim-bp-field">
										<label class="sim-bp-label" for="sim-max-posts">${ esc( __( 'Stop after', 'smart-image-matcher' ) ) }</label>
										<div class="sim-bp-inline">
											<input type="number" id="sim-max-posts" min="1" max="5000" placeholder="${ esc( __( 'No limit', 'smart-image-matcher' ) ) }" class="sim-bp-narrow" />
											<span class="sim-bp-help">${ esc( __( 'articles', 'smart-image-matcher' ) ) }</span>
										</div>
									</div>
								</div>
							</details>
						</div>
					</div>
				</div>

				<div class="sim-bp-section" id="sim-opts-process">
					<div class="sim-bp-section-head"><h2><span class="sim-bp-step">${ esc( __( 'Step 3', 'smart-image-matcher' ) ) }</span>${ esc( __( 'Match options', 'smart-image-matcher' ) ) }</h2></div>
					<div class="sim-bp-fields">
						<div class="sim-bp-field sim-bp-full">
							<span class="sim-bp-label" id="sim-lbl-fill">${ esc( __( 'What to fill', 'smart-image-matcher' ) ) }</span>
							<div class="sim-bp-seg" role="radiogroup" aria-labelledby="sim-lbl-fill">
								<label><input type="radio" name="sim-fill" value="both" checked />${ esc( __( 'Featured image and headings', 'smart-image-matcher' ) ) }</label>
								<label><input type="radio" name="sim-fill" value="featured" />${ esc( __( 'Featured image only', 'smart-image-matcher' ) ) }</label>
								<label><input type="radio" name="sim-fill" value="headings" />${ esc( __( 'Headings only', 'smart-image-matcher' ) ) }</label>
							</div>
							<span class="sim-bp-help">${ esc( __( 'Featured image only never adds images to the article body.', 'smart-image-matcher' ) ) }</span>
						</div>
						<div class="sim-bp-field sim-bp-full" id="sim-overwrite-row">
							<div class="sim-bp-toggle-row">
								<label class="sim-bp-switch"><input type="checkbox" id="sim-overwrite-featured" /><span></span></label>
								<div>
									<label class="sim-bp-label" for="sim-overwrite-featured">${ esc( __( 'Replace featured images that are already set', 'smart-image-matcher' ) ) }</label>
									<div class="sim-bp-help">${ esc( __( 'Only exact and prefix filename matches are ever assigned automatically; anything weaker goes to Review.', 'smart-image-matcher' ) ) }</div>
								</div>
							</div>
						</div>
					</div>
				</div>

				<div class="sim-bp-section" id="sim-opts-generate" hidden>
					<div class="sim-bp-section-head"><h2><span class="sim-bp-step">${ esc( __( 'Step 3', 'smart-image-matcher' ) ) }</span>${ esc( __( 'Image options', 'smart-image-matcher' ) ) }</h2></div>
					<div class="sim-bp-field">
						<span class="sim-bp-label" id="sim-lbl-style">${ esc( __( 'Image style', 'smart-image-matcher' ) ) }</span>
						<div class="sim-bp-styles" role="radiogroup" aria-labelledby="sim-lbl-style">
							<label class="sim-bp-style"><input type="radio" name="sim-gen-style" value="photo" checked /><i class="is-photo"></i><b>${ esc( __( 'Photo', 'smart-image-matcher' ) ) }</b></label>
							<label class="sim-bp-style"><input type="radio" name="sim-gen-style" value="illustration" /><i class="is-illustration"></i><b>${ esc( __( 'Illustration', 'smart-image-matcher' ) ) }</b></label>
						</div>
					</div>
					<p class="sim-bp-notice is-warn">${ esc( __( 'Each image uses your image provider’s credits and appears on the post when it finishes. You can cancel while it runs.', 'smart-image-matcher' ) ) }</p>
				</div>
			</form>

			<aside class="sim-bp-rail">
				<div class="sim-bp-card sim-bp-section sim-bp-summary" id="sim-summary">
					<div class="sim-bp-big" id="sim-sum-count">…</div>
					<div class="sim-bp-help" id="sim-sum-label">${ esc( __( 'counting articles…', 'smart-image-matcher' ) ) }</div>
					<div class="sim-bp-bands" id="sim-sum-bands"></div>
					<div class="sim-bp-kv" id="sim-sum-kv"></div>
					<button type="button" class="button button-primary button-hero sim-bp-wide" id="sim-run-primary" disabled>${ esc( __( 'Match articles', 'smart-image-matcher' ) ) }</button>
					<p class="sim-bp-help">${ esc( __( 'Runs in the background. You can leave this page.', 'smart-image-matcher' ) ) }</p>
					<div id="sim-run-notice"></div>
				</div>
				<div class="sim-bp-card sim-bp-section" id="sim-progress" hidden></div>
			</aside>
		</div>`;
	}

	function mode() {
		return q( 'input[name="sim-run-mode"]:checked' )?.value || 'process';
	}

	function radioValue( name, fallback ) {
		return q( `input[name="${ name }"]:checked` )?.value || fallback;
	}

	function setRadio( name, value ) {
		const el = q( `input[name="${ name }"][value="${ value }"]` );
		if ( el && ! el.disabled ) el.checked = true;
	}

	function statuses() {
		return qAll( '.sim-post-status' ).filter( ( el ) => el.checked ).map( ( el ) => el.value );
	}

	function splitRefs( value ) {
		const refs = String( value || '' ).split( /[\s,]+/ ).map( ( s ) => s.trim() ).filter( Boolean );
		return {
			ids: refs.filter( ( s ) => /^\d+$/.test( s ) ).map( ( s ) => parseInt( s, 10 ) ),
			slugs: refs.filter( ( s ) => ! /^\d+$/.test( s ) ),
		};
	}

	function val( sel ) {
		return q( sel )?.value || '';
	}

	function captureSelection() {
		return {
			postType: val( '#sim-post-type' ) || 'post',
			postRefs: val( '#sim-post-refs' ),
			statuses: statuses(),
			search: val( '#sim-post-search' ),
			taxonomyFilters: val( '#sim-taxonomy-filters' ),
			dateAfter: val( '#sim-date-after' ),
			dateBefore: val( '#sim-date-before' ),
			modifiedAfter: val( '#sim-modified-after' ),
			modifiedBefore: val( '#sim-modified-before' ),
			featuredFilter: radioValue( 'sim-featured-filter', 'any' ),
			contentFilter: val( '#sim-content-filter' ) || 'any',
			maxPosts: val( '#sim-max-posts' ),
			mode: mode(),
			overwrite: !! q( '#sim-overwrite-featured' )?.checked,
			fill: radioValue( 'sim-fill', 'both' ),
			style: radioValue( 'sim-gen-style', 'photo' ),
		};
	}

	function applySelection( sel ) {
		if ( ! sel || typeof sel !== 'object' ) return;
		const fields = {
			'#sim-post-type': sel.postType,
			'#sim-post-refs': sel.postRefs,
			'#sim-post-search': sel.search,
			'#sim-taxonomy-filters': sel.taxonomyFilters,
			'#sim-date-after': sel.dateAfter,
			'#sim-date-before': sel.dateBefore,
			'#sim-modified-after': sel.modifiedAfter,
			'#sim-modified-before': sel.modifiedBefore,
			'#sim-content-filter': sel.contentFilter,
			'#sim-max-posts': sel.maxPosts === '5000' ? '' : sel.maxPosts,
		};
		Object.entries( fields ).forEach( ( [ s, v ] ) => {
			const el = q( s );
			if ( el && v !== undefined ) el.value = v;
		} );
		const list = Array.isArray( sel.statuses ) ? sel.statuses : [];
		qAll( '.sim-post-status' ).forEach( ( el ) => {
			el.checked = list.includes( el.value );
		} );
		if ( sel.featuredFilter ) setRadio( 'sim-featured-filter', sel.featuredFilter );
		if ( sel.fill ) setRadio( 'sim-fill', sel.fill );
		if ( sel.style ) setRadio( 'sim-gen-style', sel.style );
		if ( sel.mode ) setRadio( 'sim-run-mode', sel.mode );
		const ow = q( '#sim-overwrite-featured' );
		if ( ow ) ow.checked = !! sel.overwrite;
		syncRunUi();
	}

	function populateSegments( selected ) {
		const select = q( '#sim-saved-segment' );
		if ( ! select ) return;
		const segments = read( KEY_SEGMENTS, {} ) || {};
		select.innerHTML = `<option value="">${ esc( __( 'Saved selections…', 'smart-image-matcher' ) ) }</option>` +
			Object.keys( segments ).sort().map( ( name ) => `<option value="${ esc( name ) }">${ esc( name ) }</option>` ).join( '' );
		select.value = selected || '';
		q( '#sim-delete-segment' ).hidden = ! select.value;
	}

	function jobBody( runMode ) {
		const sel = captureSelection();
		const refs = splitRefs( sel.postRefs );
		const max = parseInt( sel.maxPosts, 10 );
		const body = {
			post_type: sel.postType,
			post_statuses: sel.statuses,
			search: sel.search,
			taxonomy_filters: sel.taxonomyFilters,
			date_after: sel.dateAfter,
			date_before: sel.dateBefore,
			modified_after: sel.modifiedAfter,
			modified_before: sel.modifiedBefore,
			// Generating already targets only posts without a featured image.
			featured_filter: runMode === 'generate-featured' ? 'any' : sel.featuredFilter,
			content_filter: sel.contentFilter,
			max_posts: max > 0 ? Math.min( 5000, max ) : 5000,
			mode: runMode,
			overwrite: sel.overwrite,
			fill: [ 'featured', 'headings' ].includes( sel.fill ) ? sel.fill : 'both',
			style: sel.style === 'illustration' ? 'illustration' : 'photo',
		};
		if ( refs.ids.length ) body.post_ids = refs.ids;
		if ( refs.slugs.length ) body.post_slugs = refs.slugs;
		return body;
	}

	function syncRunUi() {
		const isGen = mode() === 'generate-featured';
		q( '#sim-opts-process' ).hidden = isGen;
		q( '#sim-opts-generate' ).hidden = ! isGen;
		q( '#sim-overwrite-row' ).hidden = radioValue( 'sim-fill', 'both' ) === 'headings';

		// Generating only ever targets posts without a featured image.
		const featuredSeg = q( 'input[name="sim-featured-filter"]' )?.closest( '.sim-bp-field' );
		if ( featuredSeg ) featuredSeg.hidden = isGen;

		const active = [];
		if ( val( '#sim-post-refs' ).trim() ) active.push( __( 'specific articles', 'smart-image-matcher' ) );
		if ( val( '#sim-taxonomy-filters' ).trim() ) active.push( __( 'taxonomy', 'smart-image-matcher' ) );
		if ( val( '#sim-content-filter' ) && val( '#sim-content-filter' ) !== 'any' ) active.push( __( 'content', 'smart-image-matcher' ) );
		if ( val( '#sim-date-after' ) || val( '#sim-date-before' ) || val( '#sim-modified-after' ) || val( '#sim-modified-before' ) ) active.push( __( 'dates', 'smart-image-matcher' ) );
		if ( val( '#sim-max-posts' ) ) active.push( sprintf( __( 'stop after %s', 'smart-image-matcher' ), val( '#sim-max-posts' ) ) );
		const label = q( '#sim-active-filters' );
		if ( label ) label.textContent = active.length ? '· ' + active.join( ', ' ) : '';

		scheduleCount();
	}

	function scheduleCount() {
		window.clearTimeout( state.countTimer );
		const btn = q( '#sim-run-primary' );
		if ( btn ) btn.disabled = true;
		q( '#sim-sum-count' ).textContent = '…';
		state.countTimer = window.setTimeout( refreshCount, 350 );
	}

	async function refreshCount() {
		const seq = ++state.countSeq;
		const runMode = mode();
		const notice = q( '#sim-run-notice' );
		if ( notice ) notice.innerHTML = '';
		if ( ! statuses().length ) {
			renderSummary( null, __( 'Tick at least one post status.', 'smart-image-matcher' ) );
			return;
		}
		try {
			const data = await api( '/jobs', 'POST', Object.assign( jobBody( runMode ), { dry_run: true } ) );
			if ( seq !== state.countSeq ) return;
			renderSummary( parseInt( data.total || 0, 10 ) );
		} catch ( err ) {
			if ( seq !== state.countSeq ) return;
			renderSummary( null, errMsg( err, __( 'Could not count articles.', 'smart-image-matcher' ) ) );
		}
	}

	function renderSummary( count, problem ) {
		const runMode = mode();
		const isGen = runMode === 'generate-featured';
		const sel = captureSelection();
		state.lastCount = count;

		q( '#sim-sum-count' ).textContent = count === null ? '—' : String( count );
		q( '#sim-sum-label' ).textContent = problem || ( isGen
			? _n( 'post without a featured image', 'posts without a featured image', count || 0, 'smart-image-matcher' )
			: _n( 'article matches', 'articles match', count || 0, 'smart-image-matcher' ) );

		const bands = q( '#sim-sum-bands' );
		if ( isGen ) {
			bands.innerHTML = `<div class="sim-bp-band is-ins"><b>1</b><span>${ esc( __( 'new featured image per post', 'smart-image-matcher' ) ) }</span></div>`;
		} else {
			const featuredNote = sel.fill === 'headings' ? '' : `<p class="sim-bp-help">${ esc( __( 'Featured images: only an exact or prefix filename match is assigned automatically.', 'smart-image-matcher' ) ) }</p>`;
			bands.innerHTML = `
				<div class="sim-bp-band is-ins"><b>≥ ${ AUTO }%</b><span>${ esc( __( 'Inserted automatically', 'smart-image-matcher' ) ) }</span></div>
				<div class="sim-bp-band is-rev"><b>${ REVIEW_MIN }–${ AUTO - 1 }%</b><span>${ esc( __( 'Sent to Review for your decision', 'smart-image-matcher' ) ) }</span></div>
				<div class="sim-bp-band is-skp"><b>&lt; ${ REVIEW_MIN }%</b><span>${ esc( __( 'Left alone', 'smart-image-matcher' ) ) }</span></div>
				${ featuredNote }`;
		}

		const statusLabels = qAll( '.sim-post-status' ).filter( ( el ) => el.checked ).map( ( el ) => el.parentElement.textContent.trim() );
		const featLabel = { any: __( 'Any', 'smart-image-matcher' ), missing: __( 'Missing', 'smart-image-matcher' ), has: __( 'Has one', 'smart-image-matcher' ) }[ sel.featuredFilter ];
		const fillLabel = { both: __( 'Featured image and headings', 'smart-image-matcher' ), featured: __( 'Featured image only', 'smart-image-matcher' ), headings: __( 'Headings only', 'smart-image-matcher' ) }[ sel.fill ];
		const rows = [
			[ __( 'Statuses', 'smart-image-matcher' ), statusLabels.join( ', ' ) || '—' ],
		];
		if ( isGen ) {
			rows.push( [ __( 'Style', 'smart-image-matcher' ), sel.style === 'illustration' ? __( 'Illustration', 'smart-image-matcher' ) : __( 'Photo', 'smart-image-matcher' ) ] );
		} else {
			rows.push( [ __( 'Featured image', 'smart-image-matcher' ), featLabel ] );
			rows.push( [ __( 'Fills', 'smart-image-matcher' ), fillLabel ] );
		}
		q( '#sim-sum-kv' ).innerHTML = rows.map( ( [ k, v ] ) => `<div><span>${ esc( k ) }</span><strong>${ esc( v ) }</strong></div>` ).join( '' );

		const btn = q( '#sim-run-primary' );
		btn.disabled = ! count;
		btn.textContent = isGen
			? sprintf( _n( 'Generate %d featured image…', 'Generate %d featured images…', count || 0, 'smart-image-matcher' ), count || 0 )
			: sprintf( _n( 'Match %d article', 'Match %d articles', count || 0, 'smart-image-matcher' ), count || 0 );
	}

	function runNotice( message, type ) {
		const box = q( '#sim-run-notice' );
		if ( box ) box.innerHTML = `<div class="notice notice-${ type || 'info' } inline"><p>${ esc( message ) }</p></div>`;
	}

	function buildRun() {
		const root = q( '.sim-bulk-panel[data-panel="run"]' );
		root.innerHTML = runHtml();
		populateSegments();

		const style = config.defaultStyle === 'illustration' ? 'illustration' : 'photo';
		setRadio( 'sim-gen-style', style );

		// Prefill from links (Generate bulk action, Missing Images page).
		if ( app.dataset.simMode ) setRadio( 'sim-run-mode', app.dataset.simMode );
		if ( app.dataset.simPostType && postTypes[ app.dataset.simPostType ] ) q( '#sim-post-type' ).value = app.dataset.simPostType;
		if ( app.dataset.simPostIds ) {
			q( '#sim-post-refs' ).value = app.dataset.simPostIds.replace( /,/g, ', ' );
			q( '#sim-more-filters' ).open = true;
		}
		app.dataset.simMode = '';
		app.dataset.simPostIds = '';

		const form = q( '#sim-run-form' );
		form.addEventListener( 'submit', ( e ) => e.preventDefault() );
		form.addEventListener( 'change', syncRunUi );
		form.addEventListener( 'input', ( e ) => {
			if ( e.target.matches( 'input[type="search"], input[type="text"], textarea, input[type="number"]' ) ) syncRunUi();
		} );

		q( '#sim-saved-segment' ).addEventListener( 'change', ( e ) => {
			e.stopPropagation();
			const name = e.target.value;
			const segments = read( KEY_SEGMENTS, {} ) || {};
			q( '#sim-delete-segment' ).hidden = ! name;
			if ( name && segments[ name ] ) applySelection( segments[ name ] );
		} );
		q( '#sim-save-segment' ).addEventListener( 'click', () => {
			const name = ( window.prompt( __( 'Save this selection as:', 'smart-image-matcher' ) ) || '' ).trim();
			if ( ! name ) return;
			const segments = read( KEY_SEGMENTS, {} ) || {};
			segments[ name ] = captureSelection();
			store( KEY_SEGMENTS, segments );
			populateSegments( name );
			toast( sprintf( __( 'Saved “%s”.', 'smart-image-matcher' ), name ) );
		} );
		q( '#sim-delete-segment' ).addEventListener( 'click', () => {
			const name = q( '#sim-saved-segment' ).value;
			if ( ! name ) return;
			const segments = read( KEY_SEGMENTS, {} ) || {};
			const removed = segments[ name ];
			delete segments[ name ];
			store( KEY_SEGMENTS, segments );
			populateSegments();
			toast( sprintf( __( 'Deleted “%s”.', 'smart-image-matcher' ), name ), () => {
				const again = read( KEY_SEGMENTS, {} ) || {};
				again[ name ] = removed;
				store( KEY_SEGMENTS, again );
				populateSegments( name );
			} );
		} );

		q( '#sim-run-primary' ).addEventListener( 'click', () => {
			if ( mode() === 'generate-featured' ) {
				openGenerateModal( state.lastCount || 0 );
			} else {
				startJob( 'process' );
			}
		} );

		syncRunUi();
	}

	function openGenerateModal( total ) {
		const n = parseInt( total || 0, 10 );
		if ( n < 1 ) {
			runNotice( __( 'No posts in this selection are missing a featured image.', 'smart-image-matcher' ), 'warning' );
			return;
		}
		q( '#sim-gen-modal-body' ).textContent = sprintf( _n( 'This queues %d featured image for a post with no real featured image.', 'This queues %d featured images for posts with no real featured image.', n, 'smart-image-matcher' ), n );
		q( '#sim-gen-confirm' ).textContent = sprintf( _n( 'Generate %d featured image', 'Generate %d featured images', n, 'smart-image-matcher' ), n );
		q( '#sim-gen-modal' ).hidden = false;
		q( '#sim-gen-confirm' ).focus();
	}

	async function startJob( runMode ) {
		const btn = q( '#sim-run-primary' );
		if ( btn ) btn.disabled = true;
		try {
			const data = await api( '/jobs', 'POST', jobBody( runMode ) );
			state.job = data;
			store( KEY_JOB, data.job_id );
			state.pace = null;
			renderProgress( data );
			startPolling( data.job_id );
		} catch ( err ) {
			runNotice( errMsg( err, __( 'Could not start the run.', 'smart-image-matcher' ) ), 'error' );
			if ( btn ) btn.disabled = false;
		}
	}

	function startPolling( jobId ) {
		window.clearInterval( state.pollTimer );
		state.pollTimer = window.setInterval( () => pollJob( jobId ), 3000 );
		pollJob( jobId );
	}

	async function pollJob( jobId ) {
		try {
			const data = await api( '/jobs/' + encodeURIComponent( jobId ) );
			state.job = data;
			const done = num( data, 'done' );
			if ( ! state.pace ) state.pace = { t: Date.now(), done };
			renderProgress( data );
			if ( [ 'completed', 'failed', 'cancelled' ].includes( data.status ) ) {
				window.clearInterval( state.pollTimer );
				state.pollTimer = null;
				store( KEY_JOB, null );
				refreshReviewCount();
			}
		} catch ( err ) {
			window.clearInterval( state.pollTimer );
			state.pollTimer = null;
		}
	}

	function etaText( job ) {
		const total = num( job, 'total' );
		const done = num( job, 'done' );
		if ( ! state.pace || done <= state.pace.done ) return __( 'Working…', 'smart-image-matcher' );
		const perMs = ( Date.now() - state.pace.t ) / ( done - state.pace.done );
		const mins = Math.round( ( ( total - done ) * perMs ) / 60000 );
		return sprintf( __( 'About %s left', 'smart-image-matcher' ), minutesLabel( mins ) );
	}

	function renderProgress( job ) {
		const box = q( '#sim-progress' );
		const summary = q( '#sim-summary' );
		if ( ! box || ! summary ) return;

		const running = isRunning( job );
		const total = num( job, 'total' );
		const done = num( job, 'done' );
		const pct = total > 0 ? Math.min( 100, Math.round( ( done / total ) * 100 ) ) : 0;
		const cfg = jobConfig( job );
		const isGen = cfg.mode === 'generate-featured';
		const review = num( job, 'review' );
		const errors = num( job, 'errors' );
		const fill = cfg.fill || 'both';

		const tiles = isGen
			? [
				[ num( job, 'generated' ), __( 'Generated', 'smart-image-matcher' ) ],
				[ num( job, 'skipped' ), __( 'Skipped', 'smart-image-matcher' ) ],
				[ errors, __( 'Errors', 'smart-image-matcher' ) ],
				[ Math.max( 0, total - done ), __( 'Waiting', 'smart-image-matcher' ) ],
			]
			: [
				[ num( job, 'inserted' ), __( 'Inserted', 'smart-image-matcher' ) ],
				[ review, __( 'To review', 'smart-image-matcher' ) ],
				[ num( job, 'skipped' ), __( 'No good match', 'smart-image-matcher' ) ],
				[ num( job, 'has_image' ), __( 'Had an image', 'smart-image-matcher' ) ],
			];

		const fillNote = { featured: __( 'featured image only', 'smart-image-matcher' ), headings: __( 'headings only', 'smart-image-matcher' ) }[ fill ];
		const statusText = {
			queued: __( 'Queued', 'smart-image-matcher' ),
			processing: __( 'Running', 'smart-image-matcher' ),
			completed: __( 'Finished', 'smart-image-matcher' ),
			failed: __( 'Stopped', 'smart-image-matcher' ),
			cancelled: __( 'Cancelled', 'smart-image-matcher' ),
		}[ job.status ] || job.status;

		let actions = '';
		if ( running ) {
			actions = `<button type="button" class="button sim-bp-danger" id="sim-cancel-run">${ esc( __( 'Cancel run', 'smart-image-matcher' ) ) }</button>
				<button type="button" class="button" data-goto-tab="review">${ esc( __( 'Open Review', 'smart-image-matcher' ) ) }</button>`;
		} else {
			if ( review > 0 ) {
				actions += `<button type="button" class="button button-primary" id="sim-goto-review-last">${ esc( sprintf( _n( 'Review %d suggestion', 'Review %d suggestions', review, 'smart-image-matcher' ), review ) ) }</button>`;
			}
			actions += `<button type="button" class="button${ review > 0 ? '' : ' button-primary' }" id="sim-run-again">${ esc( __( 'New run', 'smart-image-matcher' ) ) }</button>`;
		}

		const error = job.error_message ? `<p class="sim-bp-notice is-warn">${ esc( job.error_message ) }</p>` : '';
		const slotNote = isGen ? '' : `<p class="sim-bp-help">${ esc( __( 'Counts are per slot: the featured image and each heading count once.', 'smart-image-matcher' ) ) }</p>`;

		box.innerHTML = `
			<div class="sim-bp-section-head sim-bp-tight"><h2>${ esc( statusText ) }</h2><span class="sim-bp-help">${ esc( sprintf( __( '%1$d / %2$d articles', 'smart-image-matcher' ), done, total ) ) }</span></div>
			<p class="sim-bp-help">${ esc( job.label || '' ) }${ fillNote ? ' · ' + esc( fillNote ) : '' }</p>
			<div class="sim-bp-track" role="progressbar" aria-valuemin="0" aria-valuemax="${ total }" aria-valuenow="${ done }"><div style="width:${ pct }%"></div></div>
			<div class="sim-bp-help">${ esc( running ? etaText( job ) : ( errors ? sprintf( _n( '%d article failed.', '%d articles failed.', errors, 'smart-image-matcher' ), errors ) : '' ) ) }</div>
			<div class="sim-bp-tally">${ tiles.map( ( [ n, l ] ) => `<div><b>${ n }</b><span>${ esc( l ) }</span></div>` ).join( '' ) }</div>
			${ slotNote }
			${ error }
			<div class="sim-bp-actions">${ actions }</div>`;

		box.hidden = false;
		summary.hidden = true;
		q( '#sim-run-pill' ).hidden = ! running;

		q( '#sim-cancel-run', box )?.addEventListener( 'click', () => cancelJob( job.job_id ) );
		q( '#sim-goto-review-last', box )?.addEventListener( 'click', () => {
			state.review.run = 'last';
			state.review.page = 1;
			setTab( 'review' );
		} );
		q( '#sim-run-again', box )?.addEventListener( 'click', () => {
			store( KEY_JOB, null );
			state.job = null;
			box.hidden = true;
			summary.hidden = false;
			scheduleCount();
		} );
	}

	async function cancelJob( jobId ) {
		if ( ! jobId ) return;
		if ( ! window.confirm( __( 'Cancel this run? Articles already processed keep their images.', 'smart-image-matcher' ) ) ) return;
		const ids = ( read( KEY_CANCELLED, [] ) || [] ).filter( ( id ) => id !== jobId );
		ids.unshift( jobId );
		store( KEY_CANCELLED, ids.slice( 0, 20 ) );
		try {
			await api( '/jobs/' + encodeURIComponent( jobId ) + '/cancel', 'POST' );
		} catch ( e ) {}
		window.clearInterval( state.pollTimer );
		state.pollTimer = null;
		store( KEY_JOB, null );
		state.job = Object.assign( {}, state.job || {}, { status: 'cancelled' } );
		renderProgress( state.job );
	}

	async function restoreJob() {
		const jobId = read( KEY_JOB, null );
		if ( ! jobId || ( read( KEY_CANCELLED, [] ) || [] ).includes( jobId ) ) {
			store( KEY_JOB, null );
			return;
		}
		try {
			const data = await api( '/jobs/' + encodeURIComponent( jobId ) );
			state.job = data;
			renderProgress( data );
			if ( isRunning( data ) ) startPolling( jobId );
		} catch ( e ) {
			store( KEY_JOB, null );
		}
	}

	// ---------------------------------------------------------------------
	// Review tab
	// ---------------------------------------------------------------------

	function reviewShellHtml() {
		const r = state.review;
		const opt = ( v, l, cur ) => `<option value="${ v }"${ v === cur ? ' selected' : '' }>${ esc( l ) }</option>`;
		return `
		<div class="sim-bp-card">
			<div class="sim-bp-toolbar">
				<label class="screen-reader-text" for="sim-r-search">${ esc( __( 'Search', 'smart-image-matcher' ) ) }</label>
				<input type="search" class="sim-bp-search" id="sim-r-search" value="${ esc( r.search ) }" placeholder="${ esc( __( 'Search article, heading or post ID…', 'smart-image-matcher' ) ) }" />
				<label class="screen-reader-text" for="sim-r-slot">${ esc( __( 'Slot', 'smart-image-matcher' ) ) }</label>
				<select id="sim-r-slot">
					${ opt( 'all', __( 'Headings + featured', 'smart-image-matcher' ), r.slot ) }
					${ opt( 'heading', __( 'Headings only', 'smart-image-matcher' ), r.slot ) }
					${ opt( 'featured', __( 'Featured only', 'smart-image-matcher' ), r.slot ) }
				</select>
				<label class="screen-reader-text" for="sim-r-band">${ esc( __( 'Score', 'smart-image-matcher' ) ) }</label>
				<select id="sim-r-band">
					${ opt( 'all', __( 'Any score', 'smart-image-matcher' ), r.band ) }
					${ opt( 'hi', sprintf( __( '%d%% and up', 'smart-image-matcher' ), AUTO ), r.band ) }
					${ opt( 'mid', sprintf( __( '%1$d–%2$d%%', 'smart-image-matcher' ), MID, AUTO - 1 ), r.band ) }
					${ opt( 'lo', sprintf( __( 'Below %d%%', 'smart-image-matcher' ), MID ), r.band ) }
				</select>
				<label class="screen-reader-text" for="sim-r-run">${ esc( __( 'Source', 'smart-image-matcher' ) ) }</label>
				<select id="sim-r-run">
					${ opt( 'all', __( 'All runs', 'smart-image-matcher' ), r.run ) }
					${ opt( 'last', __( 'Last run only', 'smart-image-matcher' ), r.run ) }
				</select>
			</div>
			<div class="sim-bp-review-meta">
				<span id="sim-r-meta"></span>
				<button type="button" class="button-link" id="sim-approve-above">${ esc( sprintf( __( 'Approve every suggestion ≥ %d%% (all articles)', 'smart-image-matcher' ), AUTO ) ) }</button>
			</div>
			<div class="sim-bp-bulkbar" id="sim-bulkbar">
				<label class="sim-bp-inline"><input type="checkbox" id="sim-sel-all" /> <span id="sim-sel-count">${ esc( __( 'Select all on this page', 'smart-image-matcher' ) ) }</span></label>
				<span class="sim-bp-grow"></span>
				<button type="button" class="button" id="sim-b-approve" disabled>${ esc( sprintf( __( 'Approve ≥ %d%%', 'smart-image-matcher' ), AUTO ) ) }</button>
				<button type="button" class="button sim-bp-danger" id="sim-b-reject" disabled>${ esc( __( 'Reject all', 'smart-image-matcher' ) ) }</button>
				<button type="button" class="button" id="sim-b-ignore" disabled>${ esc( __( 'Ignore articles', 'smart-image-matcher' ) ) }</button>
			</div>
			<div id="sim-articles"><p class="sim-bp-loading">${ esc( __( 'Loading…', 'smart-image-matcher' ) ) }</p></div>
			<div class="sim-bp-pager" id="sim-pager"></div>
		</div>`;
	}

	function buildReviewShell() {
		const root = q( '.sim-bulk-panel[data-panel="review"]' );
		root.innerHTML = reviewShellHtml();

		let searchTimer = null;
		q( '#sim-r-search' ).addEventListener( 'input', ( e ) => {
			window.clearTimeout( searchTimer );
			searchTimer = window.setTimeout( () => {
				state.review.search = e.target.value.trim();
				state.review.page = 1;
				fetchReview();
			}, 350 );
		} );
		[ [ '#sim-r-slot', 'slot' ], [ '#sim-r-band', 'band' ], [ '#sim-r-run', 'run' ] ].forEach( ( [ sel, key ] ) => {
			q( sel ).addEventListener( 'change', ( e ) => {
				state.review[ key ] = e.target.value;
				state.review.page = 1;
				if ( key === 'run' ) setTab( 'review' );
				else fetchReview();
			} );
		} );
		q( '#sim-approve-above' ).addEventListener( 'click', approveAboveAll );
		q( '#sim-sel-all' ).addEventListener( 'change', ( e ) => {
			state.review.articles.forEach( ( a ) => {
				if ( e.target.checked ) state.review.selected.add( a.post_id );
				else state.review.selected.delete( a.post_id );
			} );
			renderArticles();
		} );
		q( '#sim-b-approve' ).addEventListener( 'click', () => bulkDecide( 'approve' ) );
		q( '#sim-b-reject' ).addEventListener( 'click', () => bulkDecide( 'reject' ) );
		q( '#sim-b-ignore' ).addEventListener( 'click', bulkIgnore );

		const list = q( '#sim-articles' );
		list.addEventListener( 'click', onArticlesClick );
		list.addEventListener( 'change', ( e ) => {
			if ( e.target.matches( '.sim-a-check' ) ) {
				const id = parseInt( e.target.value, 10 );
				if ( e.target.checked ) state.review.selected.add( id );
				else state.review.selected.delete( id );
				syncBulkbar();
			}
		} );
		state.review.loadedOnce = true;
	}

	function loadReview() {
		if ( ! state.review.loadedOnce ) buildReviewShell();
		else q( '#sim-r-run' ).value = state.review.run;
		fetchReview();
	}

	async function fetchReview() {
		const r = state.review;
		const list = q( '#sim-articles' );
		const params = new URLSearchParams( {
			page: String( r.page ),
			per_page: String( PER_PAGE ),
			slot: r.slot,
			run: r.run,
			band: r.band,
			search: r.search,
		} );
		list.classList.add( 'is-busy' );
		try {
			const data = await api( '/review?' + params.toString() );
			r.articles = Array.isArray( data.articles ) ? data.articles : [];
			r.totalArticles = parseInt( data.total_articles || 0, 10 );
			r.totalSuggestions = parseInt( data.total_suggestions || 0, 10 );
			state.tallies.approved = parseInt( data.approved_waiting || 0, 10 );
			r.selected = new Set( [ ...r.selected ].filter( ( id ) => r.articles.some( ( a ) => a.post_id === id ) ) );
			r.decided = new Map();
			r.focus = -1;
			if ( r.articles.length && ! r.articles.some( ( a ) => r.open.has( a.post_id ) ) ) {
				r.open.add( r.articles[ 0 ].post_id );
			}
			renderArticles();
			renderPager();
			updateDock();
			if ( r.run === 'all' && r.slot === 'all' && r.band === 'all' && ! r.search ) setReviewCount( r.totalArticles );
		} catch ( err ) {
			list.innerHTML = `<div class="notice notice-error inline"><p>${ esc( errMsg( err, __( 'Could not load Review.', 'smart-image-matcher' ) ) ) }</p></div>`;
		} finally {
			list.classList.remove( 'is-busy' );
		}
	}

	function setReviewCount( n ) {
		const el = q( '#sim-review-count' );
		if ( ! el ) return;
		el.textContent = String( n );
		el.hidden = ! n;
	}

	async function refreshReviewCount() {
		try {
			const data = await api( '/review?per_page=1&page=1' );
			setReviewCount( parseInt( data.total_articles || 0, 10 ) );
		} catch ( e ) {}
	}

	function isFeatured( s ) {
		return s.heading_hash === 'featured' || ( s.heading_tag || '' ).toLowerCase() === 'featured';
	}

	function scoreBand( score ) {
		if ( score >= AUTO ) return 'hi';
		if ( score >= MID ) return 'mid';
		return 'lo';
	}

	function sugStatus( id ) {
		return state.review.decided.get( id ) || 'pending';
	}

	function suggestionHtml( s ) {
		const id = parseInt( s.id, 10 );
		const score = parseInt( s.confidence_score || 0, 10 );
		const st = sugStatus( id );
		const featured = isFeatured( s );
		const tag = featured ? __( 'Featured', 'smart-image-matcher' ) : ( s.heading_tag || 'h2' ).toUpperCase();
		const file = String( s.image_file || '' ).trim();
		const head = featured
			? `<span class="sim-s-lvl">${ esc( __( 'Featured image', 'smart-image-matcher' ) ) }</span>`
			: `<span class="sim-s-lvl">${ esc( tag ) }</span>${ esc( s.heading_text || '' ) }`;

		let why = '';
		if ( featured ) {
			why = s.reason ? `<span class="sim-s-reason">${ esc( s.reason ) }</span>` : '';
		} else {
			const shared = Array.isArray( s.shared ) ? s.shared : [];
			if ( shared.length ) {
				why = `<span>${ esc( __( 'Shared words:', 'smart-image-matcher' ) ) }</span>` + shared.map( ( w ) => `<span class="sim-word${ s.thin ? ' is-thin' : '' }">${ esc( w ) }</span>` ).join( '' );
				if ( s.thin ) why += `<span class="sim-s-thin">${ esc( __( 'only one word in common — check it', 'smart-image-matcher' ) ) }</span>`;
			} else {
				why = `<span class="sim-s-thin">${ esc( __( 'No shared topic words — matched on wording alone', 'smart-image-matcher' ) ) }</span>`;
			}
		}

		const thumb = s.image_url
			? `<img src="${ esc( s.image_url ) }" alt="" loading="lazy" />`
			: '';

		let btns;
		if ( st === 'pending' ) {
			btns = `<button type="button" class="sim-icon-btn is-ok" data-act="approve" title="${ esc( __( 'Approve (A)', 'smart-image-matcher' ) ) }" aria-label="${ esc( __( 'Approve', 'smart-image-matcher' ) ) }">✓</button>
				<button type="button" class="sim-icon-btn is-no" data-act="reject" title="${ esc( __( 'Reject for this heading only (R)', 'smart-image-matcher' ) ) }" aria-label="${ esc( __( 'Reject for this heading', 'smart-image-matcher' ) ) }">✕</button>
				<button type="button" class="sim-icon-btn is-more" data-act="more" aria-expanded="false" title="${ esc( __( 'More', 'smart-image-matcher' ) ) }" aria-label="${ esc( __( 'More actions', 'smart-image-matcher' ) ) }">⋯</button>`;
		} else {
			const label = st === 'approved' ? __( 'Approved', 'smart-image-matcher' ) : __( 'Rejected here', 'smart-image-matcher' );
			btns = `<span class="sim-state-lbl">${ esc( label ) }</span><button type="button" class="button button-small" data-act="undo">${ esc( __( 'Undo', 'smart-image-matcher' ) ) }</button>`;
		}

		return `<div class="sim-sug is-${ st }" data-id="${ id }" tabindex="-1">
			<button type="button" class="sim-thumb" data-act="open" aria-label="${ esc( __( 'View image', 'smart-image-matcher' ) ) }">${ thumb }<span class="sim-thumb-tag">${ esc( tag ) }</span></button>
			<div class="sim-s-text">
				<div class="sim-s-head">${ head }</div>
				${ file ? `<div class="sim-s-file">${ esc( file ) }</div>` : '' }
				<div class="sim-s-why">${ why }</div>
			</div>
			<div class="sim-s-side">
				<span class="sim-score is-${ scoreBand( score ) }">${ score }%</span>
				<div class="sim-s-btns">${ btns }</div>
			</div>
			<div class="sim-block-slot"></div>
		</div>`;
	}

	function articleHtml( a ) {
		const r = state.review;
		const id = parseInt( a.post_id, 10 );
		const rows = Array.isArray( a.headings ) ? a.headings : [];
		const open = r.open.has( id );
		const pending = rows.filter( ( s ) => sugStatus( parseInt( s.id, 10 ) ) === 'pending' );
		const hi = pending.filter( ( s ) => parseInt( s.confidence_score, 10 ) >= AUTO ).length;
		const counts = { hi: 0, mid: 0, lo: 0 };
		rows.forEach( ( s ) => counts[ scoreBand( parseInt( s.confidence_score || 0, 10 ) ) ]++ );
		const pct = ( n ) => ( rows.length ? ( n / rows.length ) * 100 : 0 ).toFixed( 1 );
		const filtered = parseInt( a.filtered || 0, 10 );

		return `<div class="sim-article${ open ? ' is-open' : '' }" data-post="${ id }">
			<div class="sim-a-row">
				<input type="checkbox" class="sim-a-check" value="${ id }"${ r.selected.has( id ) ? ' checked' : '' } aria-label="${ esc( sprintf( __( 'Select %s', 'smart-image-matcher' ), a.post_title || '#' + id ) ) }" />
				<div class="sim-a-main">
					<button type="button" class="sim-a-title" data-act="toggle" aria-expanded="${ open ? 'true' : 'false' }"><span class="sim-caret" aria-hidden="true">▶</span><span class="sim-a-t">${ esc( a.post_title || '#' + id ) }</span></button>
					<div class="sim-a-sub">
						<span>#${ id }${ a.status ? ' · ' + esc( a.status ) : '' }</span>
						<span>${ esc( sprintf( _n( '%d suggestion', '%d suggestions', pending.length, 'smart-image-matcher' ), pending.length ) ) }</span>
						<span class="sim-dist" title="${ esc( sprintf( __( '%1$d high · %2$d middle · %3$d low', 'smart-image-matcher' ), counts.hi, counts.mid, counts.lo ) ) }"><i class="is-g" style="width:${ pct( counts.hi ) }%"></i><i class="is-b" style="width:${ pct( counts.mid ) }%"></i><i class="is-w" style="width:${ pct( counts.lo ) }%"></i></span>
						${ filtered ? `<span class="sim-a-filtered" title="${ esc( __( 'Candidates dropped by the relevance rules (wrong place, no shared topic) on the last run.', 'smart-image-matcher' ) ) }">${ esc( sprintf( _n( '%d hidden by rules', '%d hidden by rules', filtered, 'smart-image-matcher' ), filtered ) ) }</span>` : '' }
						${ a.edit_url ? `<a href="${ esc( a.edit_url ) }" target="_blank" rel="noopener">${ esc( __( 'Edit', 'smart-image-matcher' ) ) }</a>` : '' }
					</div>
				</div>
				<div class="sim-a-actions">
					<button type="button" class="button button-small" data-act="approve-hi"${ hi ? '' : ' disabled' }>${ esc( sprintf( __( 'Approve ≥ %1$d%% (%2$d)', 'smart-image-matcher' ), AUTO, hi ) ) }</button>
					<button type="button" class="button button-small sim-bp-danger" data-act="reject-all"${ pending.length ? '' : ' disabled' }>${ esc( __( 'Reject all', 'smart-image-matcher' ) ) }</button>
					<button type="button" class="button button-small" data-act="ignore" title="${ esc( __( 'No run will touch this article again until you un-ignore it in Tools.', 'smart-image-matcher' ) ) }">${ esc( __( 'Ignore article', 'smart-image-matcher' ) ) }</button>
				</div>
			</div>
			${ open ? `<div class="sim-sugs">${ rows.map( suggestionHtml ).join( '' ) }</div>` : '' }
		</div>`;
	}

	function renderArticles() {
		const r = state.review;
		const list = q( '#sim-articles' );
		if ( ! r.articles.length ) {
			const filtered = r.search || r.slot !== 'all' || r.band !== 'all';
			list.innerHTML = `<div class="sim-bp-empty">
				<strong>${ esc( filtered ? __( 'Nothing matches these filters.', 'smart-image-matcher' ) : ( r.run === 'last' ? __( 'The last run sent nothing to Review.', 'smart-image-matcher' ) : __( 'Nothing to review.', 'smart-image-matcher' ) ) ) }</strong>
				<span>${ esc( __( 'Strong matches are inserted automatically. Uncertain ones wait here for you.', 'smart-image-matcher' ) ) }</span>
				${ r.run === 'last' ? `<button type="button" class="button" data-act="show-all">${ esc( __( 'Show all runs', 'smart-image-matcher' ) ) }</button>` : '' }
			</div>`;
		} else {
			list.innerHTML = r.articles.map( articleHtml ).join( '' );
		}
		q( '#sim-r-meta' ).innerHTML = sprintf(
			__( '%1$s suggestions in %2$s articles', 'smart-image-matcher' ),
			`<strong>${ r.totalSuggestions }</strong>`,
			`<strong>${ r.totalArticles }</strong>`
		);
		syncBulkbar();
		focusRow( r.focus, false );
	}

	function renderPager() {
		const r = state.review;
		const pages = Math.max( 1, Math.ceil( r.totalArticles / PER_PAGE ) );
		const pager = q( '#sim-pager' );
		if ( pages <= 1 ) {
			pager.innerHTML = '';
			return;
		}
		pager.innerHTML = `<span>${ esc( sprintf( __( 'Page %1$d of %2$d · %3$d articles per page', 'smart-image-matcher' ), r.page, pages, PER_PAGE ) ) }</span>
			<span class="sim-bp-inline">
				<button type="button" class="button" data-page="${ r.page - 1 }"${ r.page <= 1 ? ' disabled' : '' }>‹ ${ esc( __( 'Previous', 'smart-image-matcher' ) ) }</button>
				<button type="button" class="button" data-page="${ r.page + 1 }"${ r.page >= pages ? ' disabled' : '' }>${ esc( __( 'Next', 'smart-image-matcher' ) ) } ›</button>
			</span>`;
		qAll( 'button[data-page]', pager ).forEach( ( b ) => b.addEventListener( 'click', () => {
			r.page = parseInt( b.dataset.page, 10 );
			fetchReview();
			q( '.sim-bulk-panel[data-panel="review"]' ).scrollIntoView( { block: 'start' } );
		} ) );
	}

	function syncBulkbar() {
		const n = state.review.selected.size;
		q( '#sim-sel-count' ).textContent = n ? sprintf( _n( '%d article selected', '%d articles selected', n, 'smart-image-matcher' ), n ) : __( 'Select all on this page', 'smart-image-matcher' );
		const all = q( '#sim-sel-all' );
		all.checked = n > 0 && n === state.review.articles.length;
		all.indeterminate = n > 0 && n < state.review.articles.length;
		[ '#sim-b-approve', '#sim-b-reject', '#sim-b-ignore' ].forEach( ( s ) => {
			q( s ).disabled = ! n;
		} );
		q( '#sim-bulkbar' ).classList.toggle( 'is-active', n > 0 );
	}

	function updateDock() {
		q( '#sim-dock-approved' ).textContent = String( state.tallies.approved );
		q( '#sim-dock-rejected' ).textContent = String( state.tallies.rejected );
		const btn = q( '#sim-insert-approved' );
		btn.disabled = state.tallies.approved < 1;
		btn.textContent = state.tallies.approved
			? sprintf( _n( 'Insert %d approved', 'Insert %d approved', state.tallies.approved, 'smart-image-matcher' ), state.tallies.approved )
			: __( 'Insert approved', 'smart-image-matcher' );
	}

	function findArticle( postId ) {
		return state.review.articles.find( ( a ) => parseInt( a.post_id, 10 ) === postId );
	}

	function findSug( id ) {
		for ( const a of state.review.articles ) {
			const rows = a.headings || [];
			const i = rows.findIndex( ( s ) => parseInt( s.id, 10 ) === id );
			if ( i > -1 ) return { article: a, index: i, sug: rows[ i ] };
		}
		return null;
	}

	function rerenderArticle( postId ) {
		const a = findArticle( postId );
		const el = q( `.sim-article[data-post="${ postId }"]` );
		if ( ! a || ! el ) return;
		el.outerHTML = articleHtml( a );
		focusRow( state.review.focus, false );
	}

	async function decide( ids, status ) {
		if ( ! ids.length ) return 0;
		const res = await api( '/review/decide', 'POST', { ids, status } );
		return parseInt( res.updated || 0, 10 );
	}

	function applyLocal( ids, status ) {
		const r = state.review;
		ids.forEach( ( id ) => {
			const prev = sugStatus( id );
			if ( prev === 'approved' ) state.tallies.approved--;
			if ( prev === 'rejected' ) state.tallies.rejected--;
			if ( status === 'pending' ) r.decided.delete( id );
			else r.decided.set( id, status );
			if ( status === 'approved' ) state.tallies.approved++;
			if ( status === 'rejected' ) state.tallies.rejected++;
		} );
		state.tallies.approved = Math.max( 0, state.tallies.approved );
		state.tallies.rejected = Math.max( 0, state.tallies.rejected );
		updateDock();
	}

	async function setStatus( ids, status, message ) {
		const posts = new Set( ids.map( ( id ) => findSug( id )?.article.post_id ).filter( Boolean ) );
		const before = new Map( ids.map( ( id ) => [ id, sugStatus( id ) ] ) );
		try {
			await decide( ids, status );
		} catch ( err ) {
			toast( errMsg( err, __( 'Could not save that decision.', 'smart-image-matcher' ) ) );
			return;
		}
		applyLocal( ids, status );
		posts.forEach( ( p ) => rerenderArticle( parseInt( p, 10 ) ) );
		syncModal();
		if ( message ) {
			toast( message, async () => {
				const back = ids.filter( ( id ) => before.get( id ) === 'pending' );
				try {
					await decide( back, 'pending' );
				} catch ( e ) {
					return;
				}
				applyLocal( back, 'pending' );
				posts.forEach( ( p ) => rerenderArticle( parseInt( p, 10 ) ) );
				syncModal();
			} );
		}
	}

	function pendingIds( article, minScore ) {
		return ( article.headings || [] )
			.filter( ( s ) => sugStatus( parseInt( s.id, 10 ) ) === 'pending' )
			.filter( ( s ) => minScore === undefined || parseInt( s.confidence_score, 10 ) >= minScore )
			.map( ( s ) => parseInt( s.id, 10 ) );
	}

	async function onArticlesClick( e ) {
		const btn = e.target.closest( '[data-act]' );
		if ( ! btn ) return;
		const act = btn.dataset.act;

		if ( act === 'show-all' ) {
			state.review.run = 'all';
			setTab( 'review' );
			return;
		}

		const articleEl = btn.closest( '.sim-article' );
		const postId = articleEl ? parseInt( articleEl.dataset.post, 10 ) : 0;
		const article = findArticle( postId );
		const sugEl = btn.closest( '.sim-sug' );
		const id = sugEl ? parseInt( sugEl.dataset.id, 10 ) : 0;

		if ( sugEl ) {
			const rows = visibleRows();
			state.review.focus = rows.indexOf( sugEl );
		}

		switch ( act ) {
			case 'toggle':
				if ( state.review.open.has( postId ) ) state.review.open.delete( postId );
				else state.review.open.add( postId );
				rerenderArticle( postId );
				break;
			case 'approve-hi': {
				const ids = pendingIds( article, AUTO );
				await setStatus( ids, 'approved', sprintf( _n( 'Approved %d suggestion.', 'Approved %d suggestions.', ids.length, 'smart-image-matcher' ), ids.length ) );
				break;
			}
			case 'reject-all': {
				const ids = pendingIds( article );
				await setStatus( ids, 'rejected', sprintf( _n( 'Rejected %d suggestion. Each stays rejected for its heading.', 'Rejected %d suggestions. Each stays rejected for its heading.', ids.length, 'smart-image-matcher' ), ids.length ) );
				break;
			}
			case 'ignore':
				await ignoreArticles( [ postId ] );
				break;
			case 'approve':
				await setStatus( [ id ], 'approved' );
				break;
			case 'reject':
				await setStatus( [ id ], 'rejected', __( 'Rejected for this heading. It will not be suggested here again.', 'smart-image-matcher' ) );
				break;
			case 'undo':
				await setStatus( [ id ], 'pending' );
				break;
			case 'open':
				openModal( id );
				break;
			case 'more':
				toggleBlockPanel( sugEl, btn );
				break;
			case 'block-cancel':
				closeBlockPanel( sugEl );
				break;
			case 'block-confirm':
				await blockImage( id, btn );
				break;
		}
	}

	// Site-wide block: always explained, always confirmed, never a shortcut.
	async function toggleBlockPanel( sugEl, btn ) {
		const slot = sugEl.querySelector( '.sim-block-slot' );
		if ( slot.innerHTML ) {
			closeBlockPanel( sugEl );
			return;
		}
		btn.setAttribute( 'aria-expanded', 'true' );
		slot.innerHTML = `<div class="sim-block-confirm"><p>${ esc( __( 'Checking where this image is suggested…', 'smart-image-matcher' ) ) }</p></div>`;
		const id = parseInt( sugEl.dataset.id, 10 );
		let impact = {};
		try {
			impact = await api( '/review/block-impact?match_id=' + id );
		} catch ( err ) {
			slot.innerHTML = `<div class="sim-block-confirm"><p>${ esc( errMsg( err, __( 'Could not check this image.', 'smart-image-matcher' ) ) ) }</p></div>`;
			return;
		}
		const file = impact.file || findSug( id )?.sug.image_file || '';
		const featured = parseInt( impact.other_featured || 0, 10 );
		const headings = parseInt( impact.other_headings || 0, 10 );
		slot.innerHTML = `<div class="sim-block-confirm" role="group" aria-label="${ esc( __( 'Block image site-wide', 'smart-image-matcher' ) ) }">
			<h4>${ sprintf( esc( __( 'Block %s as a featured image on every post?', 'smart-image-matcher' ) ), `<code>${ esc( file ) }</code>` ) }</h4>
			<ul>
				<li>${ esc( __( 'It will never be chosen as a featured image again, on any post.', 'smart-image-matcher' ) ) }</li>
				<li>${ esc( featured
					? sprintf( _n( '%d other waiting featured suggestion with this image is rejected too.', '%d other waiting featured suggestions with this image are rejected too.', featured, 'smart-image-matcher' ), featured )
					: __( 'No other waiting featured suggestions use it.', 'smart-image-matcher' ) ) }</li>
				<li>${ esc( headings
					? sprintf( _n( 'It can still go under headings — %d heading suggestion keeps it.', 'It can still go under headings — %d heading suggestions keep it.', headings, 'smart-image-matcher' ), headings )
					: __( 'It can still be inserted under headings.', 'smart-image-matcher' ) ) }</li>
				<li>${ esc( __( 'Featured images already set are not removed. Undo any time in Tools → Blocked images.', 'smart-image-matcher' ) ) }</li>
			</ul>
			<p class="sim-bp-help">${ esc( __( 'Wrong only for this heading? Use ✕ instead.', 'smart-image-matcher' ) ) }</p>
			<div class="sim-bp-inline">
				<button type="button" class="button sim-bp-solid-danger" data-act="block-confirm">${ esc( __( 'Block site-wide', 'smart-image-matcher' ) ) }</button>
				<button type="button" class="button" data-act="block-cancel">${ esc( __( 'Cancel', 'smart-image-matcher' ) ) }</button>
			</div>
		</div>`;
		slot.querySelector( '[data-act="block-cancel"]' ).focus();
	}

	function closeBlockPanel( sugEl ) {
		if ( ! sugEl ) return;
		sugEl.querySelector( '.sim-block-slot' ).innerHTML = '';
		const more = sugEl.querySelector( '[data-act="more"]' );
		if ( more ) {
			more.setAttribute( 'aria-expanded', 'false' );
			more.focus();
		}
	}

	async function blockImage( id, btn ) {
		btn.disabled = true;
		try {
			const res = await api( '/review/exclude-image', 'POST', { match_id: id } );
			const file = res.file || '';
			toast( sprintf( __( 'Blocked %s as a featured image site-wide.', 'smart-image-matcher' ), file ), async () => {
				try {
					await api( '/review/unblock', 'POST', { file } );
					toast( sprintf( __( 'Unblocked %s. Rejected suggestions stay rejected.', 'smart-image-matcher' ), file ) );
				} catch ( e ) {}
			} );
			closeModal();
			fetchReview();
		} catch ( err ) {
			btn.disabled = false;
			toast( errMsg( err, __( 'Could not block this image.', 'smart-image-matcher' ) ) );
		}
	}

	async function ignoreArticles( postIds ) {
		const done = [];
		for ( const id of postIds ) {
			try {
				await api( '/review/ignore', 'POST', { post_id: id, ignored: true } );
				done.push( id );
			} catch ( e ) {}
		}
		if ( ! done.length ) {
			toast( __( 'Could not ignore that article.', 'smart-image-matcher' ) );
			return;
		}
		done.forEach( ( id ) => state.review.selected.delete( id ) );
		toast(
			sprintf( _n( 'Ignored %d article. No run will touch it.', 'Ignored %d articles. No run will touch them.', done.length, 'smart-image-matcher' ), done.length ),
			async () => {
				for ( const id of done ) {
					try {
						await api( '/review/ignore', 'POST', { post_id: id, ignored: false } );
					} catch ( e ) {}
				}
				toast( __( 'Un-ignored. The next run will pick it up again.', 'smart-image-matcher' ) );
			}
		);
		fetchReview();
	}

	function selectedArticles() {
		return state.review.articles.filter( ( a ) => state.review.selected.has( parseInt( a.post_id, 10 ) ) );
	}

	async function bulkDecide( kind ) {
		const arts = selectedArticles();
		const ids = arts.flatMap( ( a ) => ( kind === 'approve' ? pendingIds( a, AUTO ) : pendingIds( a ) ) );
		if ( ! ids.length ) {
			toast( __( 'Nothing to change in the selected articles.', 'smart-image-matcher' ) );
			return;
		}
		if ( kind === 'reject' && ! window.confirm( sprintf( _n( 'Reject %d suggestion? Each stays rejected for its own heading only.', 'Reject %d suggestions? Each stays rejected for its own heading only.', ids.length, 'smart-image-matcher' ), ids.length ) ) ) {
			return;
		}
		await setStatus(
			ids,
			kind === 'approve' ? 'approved' : 'rejected',
			kind === 'approve'
				? sprintf( _n( 'Approved %d suggestion.', 'Approved %d suggestions.', ids.length, 'smart-image-matcher' ), ids.length )
				: sprintf( _n( 'Rejected %d suggestion.', 'Rejected %d suggestions.', ids.length, 'smart-image-matcher' ), ids.length )
		);
	}

	async function bulkIgnore() {
		const ids = [ ...state.review.selected ];
		if ( ! ids.length ) return;
		if ( ! window.confirm( sprintf( _n( 'Ignore %d article? Its waiting suggestions are removed and no run will touch it until you un-ignore it in Tools.', 'Ignore %d articles? Their waiting suggestions are removed and no run will touch them until you un-ignore them in Tools.', ids.length, 'smart-image-matcher' ), ids.length ) ) ) {
			return;
		}
		await ignoreArticles( ids );
	}

	async function approveAboveAll() {
		if ( ! window.confirm( sprintf( __( 'Approve every waiting suggestion scoring %d%% or more, in every article (not just this page)?', 'smart-image-matcher' ), AUTO ) ) ) return;
		try {
			const res = await api( '/review/approve-above', 'POST', { run: state.review.run } );
			toast( sprintf( _n( 'Approved %d suggestion.', 'Approved %d suggestions.', parseInt( res.approved || 0, 10 ), 'smart-image-matcher' ), parseInt( res.approved || 0, 10 ) ) );
			fetchReview();
		} catch ( err ) {
			toast( errMsg( err, __( 'Could not approve.', 'smart-image-matcher' ) ) );
		}
	}

	async function insertApproved() {
		const btn = q( '#sim-insert-approved' );
		btn.disabled = true;
		try {
			const res = await api( '/review/insert-approved', 'POST' );
			const n = parseInt( res.queued || 0, 10 );
			toast( sprintf( _n( 'Inserting into %d article in the background.', 'Inserting into %d articles in the background.', n, 'smart-image-matcher' ), n ) );
			state.tallies.approved = 0;
			state.tallies.rejected = 0;
			updateDock();
			fetchReview();
		} catch ( err ) {
			toast( errMsg( err, __( 'Could not queue the inserts.', 'smart-image-matcher' ) ) );
			updateDock();
		}
	}

	// Keyboard: J/K move, A approve, R reject here. No shortcut blocks site-wide.
	function visibleRows() {
		return qAll( '#sim-articles .sim-sug' );
	}

	function focusRow( index, scroll ) {
		const rows = visibleRows();
		rows.forEach( ( r ) => r.classList.remove( 'is-focus' ) );
		if ( index < 0 || ! rows.length ) return;
		const i = Math.min( index, rows.length - 1 );
		state.review.focus = i;
		rows[ i ].classList.add( 'is-focus' );
		if ( scroll !== false ) {
			rows[ i ].scrollIntoView( { block: 'nearest' } );
			rows[ i ].focus( { preventScroll: true } );
		}
	}

	function onKey( e ) {
		if ( e.defaultPrevented || e.ctrlKey || e.metaKey || e.altKey ) return;
		const modal = q( '#sim-review-modal' );
		if ( ! modal.hidden ) {
			if ( e.key === 'Escape' ) closeModal();
			if ( e.key === 'ArrowLeft' ) stepModal( -1 );
			if ( e.key === 'ArrowRight' ) stepModal( 1 );
			return;
		}
		if ( ! q( '#sim-gen-modal' ).hidden ) {
			if ( e.key === 'Escape' ) q( '#sim-gen-modal' ).hidden = true;
			return;
		}
		if ( state.tab !== 'review' ) return;
		if ( e.target.closest( 'input, select, textarea, .sim-block-confirm' ) ) return;
		const key = e.key.toLowerCase();
		const rows = visibleRows();
		if ( ! rows.length ) return;
		if ( key === 'j' || key === 'k' ) {
			e.preventDefault();
			const next = state.review.focus < 0 ? 0 : state.review.focus + ( key === 'j' ? 1 : -1 );
			focusRow( Math.max( 0, Math.min( rows.length - 1, next ) ) );
			return;
		}
		if ( ( key === 'a' || key === 'r' ) && state.review.focus > -1 ) {
			const row = rows[ state.review.focus ];
			if ( ! row ) return;
			e.preventDefault();
			const id = parseInt( row.dataset.id, 10 );
			if ( sugStatus( id ) !== 'pending' ) return;
			const advance = state.review.focus + 1;
			setStatus( [ id ], key === 'a' ? 'approved' : 'rejected', key === 'r' ? __( 'Rejected for this heading.', 'smart-image-matcher' ) : '' ).then( () => focusRow( advance ) );
		}
	}

	// ---------------------------------------------------------------------
	// Image modal
	// ---------------------------------------------------------------------

	function modalSug() {
		const a = findArticle( state.modal.postId );
		return a && a.headings ? a.headings[ state.modal.index ] : null;
	}

	function openModal( id ) {
		const found = findSug( id );
		if ( ! found ) return;
		state.modal.postId = parseInt( found.article.post_id, 10 );
		state.modal.index = found.index;
		renderModal();
	}

	function renderModal() {
		const s = modalSug();
		const a = findArticle( state.modal.postId );
		if ( ! s || ! a ) {
			closeModal();
			return;
		}
		const total = a.headings.length;
		const featured = isFeatured( s );
		const title = featured ? __( 'Featured image', 'smart-image-matcher' ) : ( s.heading_tag || 'h2' ).toUpperCase() + ' ' + ( s.heading_text || '' );
		q( '#sim-review-modal-title' ).textContent = title;
		q( '#sim-review-modal-meta' ).textContent = [
			a.post_title || '#' + a.post_id,
			s.image_file || '',
			sprintf( __( '%1$d of %2$d', 'smart-image-matcher' ), state.modal.index + 1, total ),
			parseInt( s.confidence_score || 0, 10 ) + '%',
		].filter( Boolean ).join( ' · ' );
		const img = q( '#sim-review-modal-img' );
		img.src = s.image_full || s.image_url || '';
		img.alt = title;
		img.hidden = ! img.src;

		const st = sugStatus( parseInt( s.id, 10 ) );
		const decided = st !== 'pending';
		const status = q( '#sim-review-modal-status' );
		status.hidden = ! decided;
		status.textContent = st === 'approved' ? __( 'Approved', 'smart-image-matcher' ) : __( 'Rejected here', 'smart-image-matcher' );
		status.className = 'sim-slot-state is-' + st;
		q( '#sim-review-modal-approve' ).hidden = decided;
		q( '#sim-review-modal-reject' ).hidden = decided;
		q( '#sim-review-modal-undo' ).hidden = ! decided;
		q( '#sim-review-modal-prev' ).disabled = state.modal.index <= 0;
		q( '#sim-review-modal-next' ).disabled = state.modal.index >= total - 1;
		q( '#sim-review-modal' ).hidden = false;
	}

	function syncModal() {
		if ( ! q( '#sim-review-modal' ).hidden ) renderModal();
	}

	function closeModal() {
		q( '#sim-review-modal' ).hidden = true;
	}

	function stepModal( delta ) {
		const a = findArticle( state.modal.postId );
		const total = a && a.headings ? a.headings.length : 0;
		state.modal.index = Math.max( 0, Math.min( total - 1, state.modal.index + delta ) );
		renderModal();
	}

	function bindModals() {
		qAll( '[data-sim-gen-cancel]' ).forEach( ( el ) => el.addEventListener( 'click', () => {
			q( '#sim-gen-modal' ).hidden = true;
		} ) );
		q( '#sim-gen-confirm' ).addEventListener( 'click', () => {
			q( '#sim-gen-modal' ).hidden = true;
			startJob( 'generate-featured' );
		} );
		qAll( '[data-sim-review-close]' ).forEach( ( el ) => el.addEventListener( 'click', closeModal ) );
		q( '#sim-review-modal-prev' ).addEventListener( 'click', () => stepModal( -1 ) );
		q( '#sim-review-modal-next' ).addEventListener( 'click', () => stepModal( 1 ) );
		const decideModal = ( status, msg ) => {
			const s = modalSug();
			if ( s ) setStatus( [ parseInt( s.id, 10 ) ], status, msg );
		};
		q( '#sim-review-modal-approve' ).addEventListener( 'click', () => decideModal( 'approved' ) );
		q( '#sim-review-modal-reject' ).addEventListener( 'click', () => decideModal( 'rejected', __( 'Rejected for this heading.', 'smart-image-matcher' ) ) );
		q( '#sim-review-modal-undo' ).addEventListener( 'click', () => decideModal( 'pending' ) );
	}

	// ---------------------------------------------------------------------
	// Health strip + Schedule tab
	// ---------------------------------------------------------------------

	function renderHealth( data ) {
		const h = data.health || {};
		const strip = q( '#sim-bp-health' );
		const queue = h.queue || {};
		strip.hidden = false;
		strip.classList.remove( 'is-good', 'is-warn', 'is-bad' );
		strip.classList.add( 'is-' + ( h.state || 'good' ) );
		q( '#sim-bp-health-title' ).textContent = h.title || '';
		const bits = [];
		if ( data.settings && data.settings.enabled && data.next_tick ) bits.push( sprintf( __( 'Next automatic run %s', 'smart-image-matcher' ), timeLabel( data.next_tick ) ) );
		if ( data.settings && ! data.settings.enabled ) bits.push( __( 'Automatic runs are off', 'smart-image-matcher' ) );
		if ( queue.pending ) bits.push( sprintf( _n( '%d action waiting', '%d actions waiting', queue.pending, 'smart-image-matcher' ), queue.pending ) );
		const stuck = ( data.open_runs || [] ).filter( ( r ) => r.stuck ).length;
		if ( stuck ) bits.push( sprintf( _n( '%d run looks stuck', '%d runs look stuck', stuck, 'smart-image-matcher' ), stuck ) );
		q( '#sim-bp-health-meta' ).textContent = bits.join( ' · ' );

		const pill = q( '#sim-schedule-pill' );
		pill.className = 'sim-bp-count';
		if ( h.state === 'bad' || stuck ) {
			pill.textContent = __( 'Blocked', 'smart-image-matcher' );
			pill.classList.add( 'is-alert' );
			pill.hidden = false;
		} else if ( h.state === 'warn' ) {
			pill.textContent = __( 'Check', 'smart-image-matcher' );
			pill.classList.add( 'is-warn' );
			pill.hidden = false;
		} else {
			pill.hidden = true;
		}
	}

	async function loadHealth() {
		try {
			const data = await api( '/schedule' );
			renderHealth( data );
			return data;
		} catch ( e ) {
			return null;
		}
	}

	async function loadSchedule() {
		const root = q( '.sim-bulk-panel[data-panel="schedule"]' );
		if ( ! root.innerHTML ) root.innerHTML = `<p class="sim-bp-loading">${ esc( __( 'Loading…', 'smart-image-matcher' ) ) }</p>`;
		const data = await loadHealth();
		if ( ! data ) {
			root.innerHTML = `<div class="notice notice-error inline"><p>${ esc( __( 'Could not load the schedule.', 'smart-image-matcher' ) ) }</p></div>`;
			return;
		}
		const s = data.settings || {};
		const featured = { any: __( 'any featured image state', 'smart-image-matcher' ), missing: __( 'missing a featured image', 'smart-image-matcher' ), has: __( 'with a featured image', 'smart-image-matcher' ) }[ s.featured ] || s.featured || '';
		const kv = [
			[ __( 'Automatic runs', 'smart-image-matcher' ), s.enabled ? __( 'On', 'smart-image-matcher' ) : __( 'Off', 'smart-image-matcher' ) ],
			[ __( 'Frequency', 'smart-image-matcher' ), s.interval || '—' ],
			[ __( 'Articles', 'smart-image-matcher' ), [ s.types, s.statuses, featured ].filter( Boolean ).join( ' · ' ) ],
			[ __( 'Fills', 'smart-image-matcher' ), s.fill || '—' ],
			[ __( 'Also on publish', 'smart-image-matcher' ), s.publish ? __( 'Yes', 'smart-image-matcher' ) : __( 'No', 'smart-image-matcher' ) ],
			[ __( 'Next run', 'smart-image-matcher' ), s.enabled ? timeLabel( data.next_tick ) : '—' ],
		];

		const runs = ( data.open_runs || [] ).map( ( r ) => {
			const missing = Math.max( 0, r.total - r.done );
			const body = r.stuck
				? sprintf( __( 'Started %1$s and stuck at %2$d / %3$d for %4$s. %5$d of its article jobs are still queued.', 'smart-image-matcher' ), r.started, r.done, r.total, minutesLabel( r.idle_min ), r.remaining )
				: sprintf( __( 'Started %1$s · %2$d / %3$d articles · last progress %4$s ago.', 'smart-image-matcher' ), r.started, r.done, r.total, minutesLabel( r.idle_min ) );
			const hint = r.remaining
				? __( 'Closing marks it finished; its queued articles still run. The next automatic run can then start.', 'smart-image-matcher' )
				: sprintf( _n( 'Closing marks it finished. %d article never ran — Re-queue lost sends failed ones back to the queue.', 'Closing marks it finished. %d articles never ran — Re-queue lost sends failed ones back to the queue.', missing, 'smart-image-matcher' ), missing );
			return `<div class="sim-bp-stuck${ r.stuck ? '' : ' is-ok' }" data-job="${ esc( r.job_id ) }">
				<h3>${ esc( r.stuck ? sprintf( __( '%s never finished', 'smart-image-matcher' ), r.label ) : sprintf( __( '%s in progress', 'smart-image-matcher' ), r.label ) ) }</h3>
				<div><code>${ esc( r.job_id ) }</code></div>
				<div>${ esc( body ) }</div>
				${ r.stuck ? `<div class="sim-bp-inline">
					<button type="button" class="button button-primary" data-act="close-job">${ esc( __( 'Close stuck job', 'smart-image-matcher' ) ) }</button>
					<button type="button" class="button" data-act="requeue">${ esc( __( 'Re-queue lost', 'smart-image-matcher' ) ) }</button>
				</div><p class="sim-bp-help">${ esc( hint ) }</p>` : '' }
			</div>`;
		} ).join( '' );

		const outcome = {
			queued: [ 'is-ran', __( 'Ran', 'smart-image-matcher' ) ],
			skipped: [ 'is-skip', __( 'Skipped', 'smart-image-matcher' ) ],
			nothing: [ 'is-none', __( 'Nothing to do', 'smart-image-matcher' ) ],
		};
		const ticks = ( data.ticks || [] ).slice( 0, 48 ).map( ( t ) => {
			const [ cls, label ] = outcome[ t.outcome ] || [ 'is-none', t.outcome ];
			return `<tr><td class="sim-num">${ esc( timeLabel( t.at ) ) }</td><td><span class="sim-pill ${ cls }">${ esc( label ) }</span></td><td>${ esc( t.detail || '' ) }</td></tr>`;
		} ).join( '' );

		root.innerHTML = `<div class="sim-bp-sched-grid">
			<div class="sim-bp-card sim-bp-section">
				<div class="sim-bp-section-head"><h2>${ esc( __( 'Automatic runs', 'smart-image-matcher' ) ) }</h2><a href="${ esc( s.settings_url || config.settingsUrl || '#' ) }">${ esc( __( 'Change in Settings', 'smart-image-matcher' ) ) }</a></div>
				<div class="sim-bp-kv is-flush">${ kv.map( ( [ k, v ] ) => `<div><span>${ esc( k ) }</span><strong>${ esc( v ) }</strong></div>` ).join( '' ) }</div>
				<div class="sim-bp-runs">${ runs || `<p class="sim-bp-help">${ esc( __( 'No run is open right now.', 'smart-image-matcher' ) ) }</p>` }</div>
			</div>
			<div class="sim-bp-card">
				<div class="sim-bp-section sim-bp-tight-bottom"><div class="sim-bp-section-head sim-bp-tight"><h2>${ esc( __( 'Recent automatic runs', 'smart-image-matcher' ) ) }</h2><span class="sim-bp-help">${ esc( __( 'Every tick is logged, including skips', 'smart-image-matcher' ) ) }</span></div></div>
				<div class="sim-bp-table-wrap">
					${ ticks ? `<table class="sim-bp-log"><thead><tr><th>${ esc( __( 'Time', 'smart-image-matcher' ) ) }</th><th>${ esc( __( 'Outcome', 'smart-image-matcher' ) ) }</th><th>${ esc( __( 'Details', 'smart-image-matcher' ) ) }</th></tr></thead><tbody>${ ticks }</tbody></table>`
						: `<p class="sim-bp-help sim-bp-pad">${ esc( __( 'No automatic run has been logged yet.', 'smart-image-matcher' ) ) }</p>` }
				</div>
			</div>
		</div>`;

		qAll( '[data-act="close-job"]', root ).forEach( ( b ) => b.addEventListener( 'click', () => closeJob( b.closest( '[data-job]' ).dataset.job, b ) ) );
		qAll( '[data-act="requeue"]', root ).forEach( ( b ) => b.addEventListener( 'click', () => requeueLost( b.closest( '[data-job]' ).dataset.job, b ) ) );
	}

	async function closeJob( jobId, btn ) {
		if ( ! window.confirm( __( 'Close this run? It is marked finished so the next automatic run can start.', 'smart-image-matcher' ) ) ) return;
		btn.disabled = true;
		try {
			await api( '/jobs/' + encodeURIComponent( jobId ) + '/close', 'POST' );
			toast( __( 'Run closed.', 'smart-image-matcher' ) );
			loadSchedule();
		} catch ( err ) {
			btn.disabled = false;
			toast( errMsg( err, __( 'Could not close the run.', 'smart-image-matcher' ) ) );
		}
	}

	async function requeueLost( jobId, btn ) {
		btn.disabled = true;
		try {
			const res = await api( '/jobs/' + encodeURIComponent( jobId ) + '/requeue-lost', 'POST' );
			const n = parseInt( res.queued || 0, 10 );
			toast( n ? sprintf( _n( 'Queued %d article again.', 'Queued %d articles again.', n, 'smart-image-matcher' ), n ) : __( 'No failed articles found for this run.', 'smart-image-matcher' ) );
		} catch ( err ) {
			toast( errMsg( err, __( 'Could not re-queue.', 'smart-image-matcher' ) ) );
		}
		btn.disabled = false;
	}

	// ---------------------------------------------------------------------
	// Tools tab
	// ---------------------------------------------------------------------

	async function loadBlocked() {
		const list = q( '#sim-blocked-list' );
		try {
			const res = await api( '/review/blocked' );
			renderBlocked( Array.isArray( res.files ) ? res.files : [] );
		} catch ( err ) {
			list.innerHTML = `<p class="sim-bp-help">${ esc( errMsg( err, __( 'Could not load.', 'smart-image-matcher' ) ) ) }</p>`;
		}
	}

	function renderBlocked( files ) {
		q( '#sim-blocked-count' ).textContent = String( files.length );
		q( '#sim-blocked-list' ).innerHTML = files.length
			? files.map( ( f ) => `<div><code>${ esc( f ) }</code><button type="button" class="button button-small" data-file="${ esc( f ) }">${ esc( __( 'Unblock', 'smart-image-matcher' ) ) }</button></div>` ).join( '' )
			: `<p class="sim-bp-help">${ esc( __( 'No blocked images.', 'smart-image-matcher' ) ) }</p>`;
	}

	async function loadIgnored() {
		const list = q( '#sim-ignored-list' );
		try {
			const res = await api( '/review/ignored' );
			const items = Array.isArray( res.articles ) ? res.articles : [];
			q( '#sim-ignored-count' ).textContent = String( items.length );
			list.innerHTML = items.length
				? items.map( ( a ) => `<div><span><a href="${ esc( a.edit_url || '#' ) }" target="_blank" rel="noopener">${ esc( a.title || '#' + a.id ) }</a> <span class="sim-bp-help">#${ parseInt( a.id, 10 ) }</span></span><button type="button" class="button button-small" data-post="${ parseInt( a.id, 10 ) }">${ esc( __( 'Un-ignore', 'smart-image-matcher' ) ) }</button></div>` ).join( '' )
				: `<p class="sim-bp-help">${ esc( __( 'No ignored articles. Use “Ignore article” in Review.', 'smart-image-matcher' ) ) }</p>`;
		} catch ( err ) {
			list.innerHTML = `<p class="sim-bp-help">${ esc( errMsg( err, __( 'Could not load.', 'smart-image-matcher' ) ) ) }</p>`;
		}
	}

	function bindToolLists() {
		q( '#sim-blocked-list' ).addEventListener( 'click', async ( e ) => {
			const b = e.target.closest( 'button[data-file]' );
			if ( ! b ) return;
			b.disabled = true;
			try {
				const res = await api( '/review/unblock', 'POST', { file: b.dataset.file } );
				renderBlocked( Array.isArray( res.files ) ? res.files : [] );
				toast( sprintf( __( 'Unblocked %s.', 'smart-image-matcher' ), b.dataset.file ) );
			} catch ( err ) {
				b.disabled = false;
				toast( errMsg( err, __( 'Could not unblock.', 'smart-image-matcher' ) ) );
			}
		} );
		q( '#sim-ignored-list' ).addEventListener( 'click', async ( e ) => {
			const b = e.target.closest( 'button[data-post]' );
			if ( ! b ) return;
			b.disabled = true;
			try {
				await api( '/review/ignore', 'POST', { post_id: parseInt( b.dataset.post, 10 ), ignored: false } );
				toast( __( 'Un-ignored. The next run will pick it up again.', 'smart-image-matcher' ) );
				loadIgnored();
			} catch ( err ) {
				b.disabled = false;
				toast( errMsg( err, __( 'Could not un-ignore.', 'smart-image-matcher' ) ) );
			}
		} );
	}

	// Fix incorrect featured images.
	let lastAudit = null;

	function auditNotice( type, message ) {
		q( '#sim-fiaa-audit-notice' ).innerHTML = `<div class="notice notice-${ type } inline"><p>${ esc( message ) }</p></div>`;
	}

	function auditSettings() {
		const list = qAll( '.sim-audit-status' ).filter( ( el ) => el.checked ).map( ( el ) => el.value );
		return { post_type: val( '#sim-audit-post-type' ) || 'post', post_statuses: list.length ? list : [ 'draft' ] };
	}

	function renderAudit( res ) {
		const unsafe = parseInt( res.unsafe || 0, 10 );
		const preview = Array.isArray( res.preview ) ? res.preview : [];
		q( '#sim-fiaa-audit-total-assigned' ).textContent = String( parseInt( res.total_assigned || 0, 10 ) );
		q( '#sim-fiaa-audit-safe' ).textContent = String( parseInt( res.safe || 0, 10 ) );
		q( '#sim-fiaa-audit-unsafe' ).textContent = String( unsafe );
		q( '#sim-fiaa-audit-summary' ).hidden = false;
		const table = q( '#sim-fiaa-audit-table' );
		table.hidden = ! preview.length;
		q( '#sim-fiaa-audit-body' ).innerHTML = preview.map( ( it ) => `<tr><td>${ esc( it.title || '#' + it.id ) }</td><td><code>${ esc( it.post_slug || '' ) }</code></td><td><code>${ esc( it.image_slug || '' ) }</code></td><td>${ esc( it.method || '' ) }</td><td>${ parseInt( it.score || 0, 10 ) }%</td></tr>` ).join( '' );
		const note = q( '#sim-fiaa-audit-preview-note' );
		note.hidden = ! ( unsafe > preview.length && preview.length );
		note.textContent = sprintf( __( 'Showing the first %1$d of %2$d.', 'smart-image-matcher' ), preview.length, unsafe );
		q( '#sim-fiaa-audit-clear-button' ).disabled = unsafe <= 0;
	}

	async function scanAudit() {
		const s = auditSettings();
		auditNotice( 'info', __( 'Scanning featured images…', 'smart-image-matcher' ) );
		const params = new URLSearchParams();
		params.set( 'post_type', s.post_type );
		s.post_statuses.forEach( ( st ) => params.append( 'post_statuses[]', st ) );
		try {
			lastAudit = await api( '/featured-image-audit?' + params.toString() );
			renderAudit( lastAudit );
			const unsafe = parseInt( lastAudit.unsafe || 0, 10 );
			auditNotice( unsafe ? 'warning' : 'success', unsafe ? sprintf( _n( 'Found %d unsafe featured image.', 'Found %d unsafe featured images.', unsafe, 'smart-image-matcher' ), unsafe ) : __( 'No unsafe featured images found.', 'smart-image-matcher' ) );
		} catch ( err ) {
			auditNotice( 'error', errMsg( err, __( 'Could not scan featured images.', 'smart-image-matcher' ) ) );
		}
	}

	async function clearAudit() {
		if ( ! lastAudit || parseInt( lastAudit.unsafe || 0, 10 ) <= 0 ) return;
		if ( ! window.confirm( __( 'Remove unsafe featured images from the scanned posts? Exact and prefix matches are left alone.', 'smart-image-matcher' ) ) ) return;
		try {
			const job = await api( '/featured-image-audit/clear', 'POST', auditSettings() );
			if ( ! job.job_id ) {
				auditNotice( 'success', job.message || __( 'No unsafe featured images found.', 'smart-image-matcher' ) );
				renderAudit( { total_assigned: 0, safe: 0, unsafe: 0, preview: [] } );
				return;
			}
			auditNotice( 'info', __( 'Cleanup queued. Scan again in a minute to see the new counts.', 'smart-image-matcher' ) );
		} catch ( err ) {
			auditNotice( 'error', errMsg( err, __( 'Could not start the cleanup.', 'smart-image-matcher' ) ) );
		}
	}

	// Recover finished fal.ai images.
	let recovery = null;

	function recoveryNotice( type, message ) {
		q( '#sim-fal-recovery-notice' ).innerHTML = `<div class="notice notice-${ type } inline"><p>${ esc( message ) }</p></div>`;
	}

	function recoveryBody( dry ) {
		const list = statuses();
		const body = {
			discover_recent: true,
			hours: parseInt( val( '#sim-fal-recovery-hours' ) || '48', 10 ),
			post_statuses: ( list.length ? list : [ 'draft' ] ).join( ',' ),
		};
		if ( dry ) body.dry_run = true;
		return body;
	}

	function cut( text ) {
		const s = String( text || '' );
		return s.length > 140 ? s.slice( 0, 137 ) + '…' : s;
	}

	async function previewRecovery() {
		recovery = null;
		recoveryNotice( 'info', __( 'Checking recently finished images…', 'smart-image-matcher' ) );
		try {
			const res = await api( '/generate-images/recover', 'POST', recoveryBody( true ) );
			recovery = res;
			const matched = Array.isArray( res.matched ) ? res.matched : [];
			const unmatched = Array.isArray( res.unmatched ) ? res.unmatched : [];
			q( '#sim-fal-recovery-matched' ).textContent = String( matched.length );
			q( '#sim-fal-recovery-unmatched' ).textContent = String( unmatched.length );
			q( '#sim-fal-recovery-summary' ).hidden = false;
			const rows = matched.map( ( it ) => `<tr><td>${ esc( it.post_title || '#' + it.post_id ) }</td><td>${ esc( it.request_id || '' ) }</td><td>${ esc( cut( it.prompt ) ) }</td><td><span class="sim-pill is-ran">${ esc( __( 'Safe match', 'smart-image-matcher' ) ) }</span></td></tr>` )
				.concat( unmatched.map( ( it ) => `<tr><td>${ esc( it.near_post_title || '—' ) }</td><td>${ esc( it.request_id || '' ) }</td><td>${ esc( cut( it.prompt ) ) }</td><td><span class="sim-pill is-skip">${ esc( it.reason || __( 'Not matched', 'smart-image-matcher' ) ) }</span></td></tr>` ) );
			q( '#sim-fal-recovery-body' ).innerHTML = rows.join( '' );
			q( '#sim-fal-recovery-table' ).hidden = ! rows.length;
			q( '#sim-fal-recovery-run-button' ).disabled = ! matched.length;
			recoveryNotice( matched.length ? 'success' : 'warning', matched.length
				? sprintf( __( '%1$d safe match(es); %2$d will be left alone.', 'smart-image-matcher' ), matched.length, unmatched.length )
				: __( 'No safe matches. Nothing will be imported.', 'smart-image-matcher' ) );
		} catch ( err ) {
			recoveryNotice( 'error', errMsg( err, __( 'Could not preview the recovery.', 'smart-image-matcher' ) ) );
		}
	}

	async function runRecovery() {
		const n = recovery && Array.isArray( recovery.matched ) ? recovery.matched.length : 0;
		if ( ! n ) return;
		if ( ! window.confirm( sprintf( _n( 'Import %d matched image into WordPress? Unmatched images are not imported.', 'Import %d matched images into WordPress? Unmatched images are not imported.', n, 'smart-image-matcher' ), n ) ) ) return;
		try {
			const res = await api( '/generate-images/recover', 'POST', recoveryBody( false ) );
			const jobs = Array.isArray( res.jobs ) ? res.jobs.length : 0;
			recoveryNotice( jobs ? 'info' : 'error', jobs ? sprintf( _n( '%d image queued for import.', '%d images queued for import.', jobs, 'smart-image-matcher' ), jobs ) : __( 'Could not queue the import.', 'smart-image-matcher' ) );
			recovery = null;
			q( '#sim-fal-recovery-run-button' ).disabled = true;
		} catch ( err ) {
			recoveryNotice( 'error', errMsg( err, __( 'Could not queue the import.', 'smart-image-matcher' ) ) );
		}
	}

	function bindTools() {
		const select = q( '#sim-audit-post-type' );
		select.innerHTML = Object.entries( postTypes ).map( ( [ slug, label ] ) => `<option value="${ esc( slug ) }">${ esc( label ) }</option>` ).join( '' );
		q( '#sim-fiaa-audit-scan-button' ).addEventListener( 'click', scanAudit );
		q( '#sim-fiaa-audit-clear-button' ).addEventListener( 'click', clearAudit );
		q( '#sim-fal-recovery-preview-button' ).addEventListener( 'click', previewRecovery );
		q( '#sim-fal-recovery-run-button' ).addEventListener( 'click', runRecovery );
		bindToolLists();
	}

	// ---------------------------------------------------------------------
	// Boot
	// ---------------------------------------------------------------------

	if ( ! apiFetch ) {
		q( '.sim-bulk-panel[data-panel="run"]' ).innerHTML = '<div class="notice notice-error inline"><p>Bulk Processor could not load because wp.apiFetch is unavailable.</p></div>';
		return;
	}

	qAll( '.sim-bp-tab' ).forEach( ( tab ) => tab.addEventListener( 'click', ( e ) => {
		e.preventDefault();
		setTab( tab.dataset.tab );
	} ) );
	app.addEventListener( 'click', ( e ) => {
		const go = e.target.closest( '[data-goto-tab]' );
		if ( go ) setTab( go.dataset.gotoTab );
	} );
	q( '#sim-insert-approved' ).addEventListener( 'click', insertApproved );
	document.addEventListener( 'keydown', onKey );

	buildRun();
	bindModals();
	bindTools();
	restoreJob();
	setTab( state.tab );
	loadHealth();
	if ( state.tab !== 'review' ) refreshReviewCount();
	window.setInterval( loadHealth, 60000 );
}() );
