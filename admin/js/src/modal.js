/**
 * modal.js — Smart Image Matcher modal (vanilla JS, no jQuery dependency).
 *
 * Communicates with:
 *   POST /wp-json/smart-image-matcher/v1/posts/<id>/match
 *   POST /wp-json/smart-image-matcher/v1/posts/<id>/insert
 *   POST /wp-json/smart-image-matcher/v1/posts/<id>/insert-batch
 *
 * Uses stable heading_hash values — NEVER byte offsets.
 * Uses SimIcons SVG helpers — never emoji.
 *
 * @package SmartImageMatcher
 * @since   3.0.0
 */

( function () {
	'use strict';

	// Data injected by wp_localize_script().
	const { ajaxUrl, nonces, postId, debug } = window.smartImageMatcherData || {};

	// SVG icon helpers (loaded via svg-icons.js before this file).
	const Icons = window.SimIcons || {};

	// REST base URL.
	const REST_BASE = '/wp-json/smart-image-matcher/v1';

	// -------------------------------------------------------------------------
	// DOM helpers
	// -------------------------------------------------------------------------

	/** @type {HTMLElement|null} */
	let modal = null;

	function getModal() {
		if ( ! modal ) {
			modal = document.getElementById( 'sim-modal' );
		}
		return modal;
	}

	function q( selector, root ) {
		return ( root || document ).querySelector( selector );
	}

	function escapeHtml( str ) {
		const div = document.createElement( 'div' );
		div.textContent = str;
		return div.innerHTML;
	}

	// -------------------------------------------------------------------------
	// State
	// -------------------------------------------------------------------------

	let currentMatches = [];   // Array of { heading, matches[] }
	let carouselIndices = {};  // headingHash → current carousel index

	// -------------------------------------------------------------------------
	// Open / close
	// -------------------------------------------------------------------------

	function openModal() {
		const el = getModal();
		if ( ! el ) return;
		el.style.display = 'block';
		showState( 'loading' );
		findMatches();
	}

	function closeModal() {
		const el = getModal();
		if ( el ) el.style.display = 'none';
		currentMatches  = [];
		carouselIndices = {};
	}

	// -------------------------------------------------------------------------
	// REST API calls
	// -------------------------------------------------------------------------

	async function request( url, body ) {
		const resp = await fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				// REST API requires the wp_rest nonce in X-WP-Nonce.
				'X-WP-Nonce': nonces.wpRest,
			},
			body: JSON.stringify( body ),
		} );
		if ( ! resp.ok ) {
			const err = await resp.json().catch( () => ( {} ) );
			throw new Error( err.message || `HTTP ${ resp.status }` );
		}
		return resp.json();
	}

	async function findMatches() {
		if ( ! postId ) {
			showError( 'No post ID available.' );
			return;
		}

		try {
			updateProgress( 30, 'Analysing post&hellip;' );
			const data = await request( `${ REST_BASE }/posts/${ postId }/match`, {
				post_id: postId,
				mode: 'keyword',
			} );
			updateProgress( 100 );

			// AI mode returns { status:'queued', poll_url } — start polling.
			if ( data.status === 'queued' && data.poll_url ) {
				updateProgress( 50, 'AI matching in progress&hellip;' );
				pollForAiResults( data.poll_url );
				return;
			}

			currentMatches = data.matches || [];
			renderResults();
		} catch ( err ) {
			showError( err.message );
		}
	}

	function pollForAiResults( pollUrl ) {
		let attempts = 0;
		const maxAttempts = 30; // 30 × 2 s = 60 s timeout

		const timer = setInterval( async () => {
			attempts++;

			if ( attempts > maxAttempts ) {
				clearInterval( timer );
				showError( 'AI matching timed out. Try again or switch to Keyword mode.' );
				return;
			}

			try {
				const data = await fetch( pollUrl, {
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': nonces.wpRest },
				} ).then( r => r.json() );

				if ( data.done ) {
					clearInterval( timer );
					currentMatches = data.matches || [];
					updateProgress( 100 );
					renderResults();
				}
			} catch ( err ) {
				// Tolerate transient network errors during polling.
			}
		}, 2000 );
	}

	async function insertOne( headingHash, imageId ) {
		try {
			await request( `${ REST_BASE }/posts/${ postId }/insert`, {
				post_id:       postId,
				heading_hash:  headingHash,
				image_id:      imageId,
			} );
			return true;
		} catch ( err ) {
			return new Error( err.message );
		}
	}

	async function insertBatch( insertions ) {
		try {
			const data = await request( `${ REST_BASE }/posts/${ postId }/insert-batch`, {
				post_id:    postId,
				insertions,
			} );
			return data;
		} catch ( err ) {
			return new Error( err.message );
		}
	}

	// -------------------------------------------------------------------------
	// Rendering
	// -------------------------------------------------------------------------

	function showState( state ) {
		const el = getModal();
		if ( ! el ) return;
		q( '.sim-loading-state', el ).style.display  = state === 'loading'  ? '' : 'none';
		q( '.sim-results-state', el ).style.display  = state === 'results'  ? '' : 'none';
		q( '.sim-error-state', el ).style.display    = state === 'error'    ? '' : 'none';
		q( '.sim-progress-state', el ).style.display = state === 'progress' ? '' : 'none';
	}

	function updateProgress( percent, message ) {
		const el = getModal();
		if ( ! el ) return;
		const bar = q( '.sim-progress-fill', el );
		if ( bar ) bar.style.width = percent + '%';
		const info = q( '.sim-loading-info', el );
		if ( info && message ) info.textContent = message;
	}

	function showError( message ) {
		const el = getModal();
		if ( ! el ) return;
		showState( 'error' );
		const msg = q( '.sim-error-message', el );
		if ( msg ) msg.textContent = message;
	}

	function showProgress( message ) {
		const el = getModal();
		if ( ! el ) return;
		showState( 'progress' );
		const info = q( '.sim-progress-info', el );
		if ( info ) info.innerHTML = message; // innerHTML so HTML entities render
	}

	function renderResults() {
		const el = getModal();
		if ( ! el ) return;
		showState( 'results' );

		const total   = currentMatches.length;
		const matched = currentMatches.filter( g => g.matches && g.matches.length > 0 ).length;

		q( '.sim-results-summary', el ).innerHTML =
			'<strong>' + matched + ' match' + ( matched !== 1 ? 'es' : '' ) +
			' found for ' + total + ' heading' + ( total !== 1 ? 's' : '' ) + '</strong>' +
			'<div class="sim-review-notice">' +
			( Icons.warning ? Icons.warning() : '' ) +
			' <strong>Please review</strong> each match before inserting. ' +
			'Use the arrows to browse alternatives. Uncheck any you don\'t want.' +
			'</div>';

		const container = q( '.sim-matches-container', el );
		container.innerHTML = '';
		carouselIndices = {};

		currentMatches.forEach( function( group ) {
			const heading = group.heading;
			const matches = group.matches;
			const hash    = heading.heading_hash;

			if ( ! matches || matches.length === 0 ) {
				container.insertAdjacentHTML( 'beforeend', renderNoMatch( heading ) );
				return;
			}

			carouselIndices[ hash ] = 0;
			container.insertAdjacentHTML( 'beforeend', renderMatchItem( heading, matches, 0 ) );

			if ( matches[ 1 ] ) preloadImage( matches[ 1 ].image_url );
		} );

		const insertAllBtn = q( '.sim-insert-all-button', el );
		if ( insertAllBtn ) insertAllBtn.style.display = '';
	}

	function renderMatchItem( heading, matches, index ) {
		const m         = matches[ index ];
		const hash      = heading.heading_hash;
		const scoreClass = m.confidence_score >= 90 ? 'sim-confidence-high'
			: m.confidence_score >= 70 ? 'sim-confidence-medium' : 'sim-confidence-low';

		const prevIcon = Icons.chevronLeft ? Icons.chevronLeft() : '&#8592;';
		const nextIcon = Icons.chevronRight ? Icons.chevronRight() : '&#8594;';
		const starBadge = Icons.star
			? '<span class="sim-best-match-badge">' + Icons.star() + ' Best</span> '
			: '<span class="sim-best-match-badge">Best</span> ';

		const carouselHtml = matches.length > 1
			? '<div class="sim-carousel-controls">' +
				'<button type="button" class="button sim-carousel-prev"' +
				' data-hash="' + escapeHtml( hash ) + '"' +
				( index === 0 ? ' disabled' : '' ) + '>' + prevIcon + ' Prev</button>' +
				'<span class="sim-carousel-counter">' +
				( index === 0 ? starBadge : '' ) +
				'<strong><span class="sim-current-index">' + ( index + 1 ) + '</span> of ' + matches.length + '</strong>' +
				'</span>' +
				'<button type="button" class="button sim-carousel-next"' +
				' data-hash="' + escapeHtml( hash ) + '"' +
				( index === matches.length - 1 ? ' disabled' : '' ) + '>Next ' + nextIcon + '</button>' +
				'</div>'
			: '';

		const checkIcon  = Icons.check  ? Icons.check()  : '';
		const titleHtml  = m.title
			? '<div class="sim-image-title"><strong>Title:</strong> ' + escapeHtml( m.title ) + '</div>'
			: '';
		const reasonHtml = m.ai_reasoning
			? '<div class="sim-ai-reasoning">' + escapeHtml( m.ai_reasoning ) + '</div>'
			: '';

		return (
			'<div class="sim-match-item"' +
			' data-hash="' + escapeHtml( hash ) + '"' +
			' data-image-id="' + m.image_id + '"' +
			' data-all-matches=\'' + escapeAttrJson( matches ) + '\'>' +
			'<div class="sim-match-heading">' +
			'<span class="sim-heading-icon">' + checkIcon + '</span>' +
			'<span>' + escapeHtml( heading.tag.toUpperCase() ) + ': ' + escapeHtml( heading.text ) + '</span>' +
			'</div>' +
			carouselHtml +
			'<div class="sim-image-preview-container">' +
			'<img src="' + escapeHtml( m.image_url ) + '" alt="" class="sim-image-preview" />' +
			'<div class="sim-image-info">' +
			'<div class="sim-confidence-score ' + scoreClass + '">' +
			'Confidence: <span class="sim-confidence-value">' + m.confidence_score + '%</span>' +
			'</div>' +
			titleHtml +
			'<div class="sim-image-filename"><strong>File:</strong> <span class="sim-filename-value">' + escapeHtml( m.filename ) + '</span></div>' +
			reasonHtml +
			'<div class="sim-match-actions">' +
			'<label><input type="checkbox" class="sim-select-checkbox" checked /> Selected</label>' +
			'<button type="button" class="button sim-insert-single-button"' +
			' data-hash="' + escapeHtml( hash ) + '">Insert Now</button>' +
			'<a href="' + escapeHtml( m.image_url ) + '" target="_blank" rel="noopener" class="button">View &#8599;</a>' +
			'</div>' +
			'</div>' +
			'</div>' +
			'</div>'
		);
	}

	function renderNoMatch( heading ) {
		const crossIcon   = Icons.cross   ? Icons.cross()   : '';
		const warnIcon    = Icons.warning ? Icons.warning() : '';
		return (
			'<div class="sim-match-item no-match">' +
			'<div class="sim-match-heading">' +
			'<span class="sim-heading-icon">' + crossIcon + '</span>' +
			'<span>' + escapeHtml( heading.tag.toUpperCase() ) + ': ' + escapeHtml( heading.text ) + '</span>' +
			'</div>' +
			'<div class="sim-no-match-warning">' + warnIcon + ' No matching image found</div>' +
			'</div>'
		);
	}

	function escapeAttrJson( value ) {
		// Safe for data-* attributes: encode to JSON, then escape quotes.
		return JSON.stringify( value )
			.replace( /&/g, '&amp;' )
			.replace( /'/g, '&#39;' )
			.replace( /"/g, '&quot;' );
	}

	function preloadImage( url ) {
		const img = new Image();
		img.src = url;
	}

	// -------------------------------------------------------------------------
	// Carousel navigation
	// -------------------------------------------------------------------------

	function navigateCarousel( hash, direction ) {
		const item = q( `.sim-match-item[data-hash="${ hash }"]` );
		if ( ! item ) return;

		const allMatches = JSON.parse( item.dataset.allMatches || '[]' );
		const heading    = currentMatches.find( g => g.heading.heading_hash === hash );
		if ( ! heading ) return;

		const current = carouselIndices[ hash ] ?? 0;
		const next    = current + direction;

		if ( next < 0 || next >= allMatches.length ) return;

		carouselIndices[ hash ] = next;
		const m = allMatches[ next ];

		// Update image-id on the item.
		item.dataset.imageId = m.image_id;

		// Update image.
		const img = q( '.sim-image-preview', item );
		if ( img ) img.src = m.image_url;

		// Update confidence.
		const scoreEl = q( '.sim-confidence-value', item );
		if ( scoreEl ) scoreEl.textContent = m.confidence_score + '%';

		const scoreDiv = q( '.sim-confidence-score', item );
		if ( scoreDiv ) {
			scoreDiv.className = 'sim-confidence-score ' + (
				m.confidence_score >= 90 ? 'sim-confidence-high' :
				m.confidence_score >= 70 ? 'sim-confidence-medium' : 'sim-confidence-low'
			);
		}

		// Update filename.
		const fnEl = q( '.sim-filename-value', item );
		if ( fnEl ) fnEl.textContent = m.filename;

		// Update counter and badges.
		const counterEl = q( '.sim-current-index', item );
		if ( counterEl ) counterEl.textContent = next + 1;

		const badge = q( '.sim-best-match-badge', item );
		if ( next === 0 && ! badge ) {
			const counterSpan = q( '.sim-carousel-counter', item );
			const starBadge   = Icons.star
				? '<span class="sim-best-match-badge">' + Icons.star() + ' Best</span> '
				: '<span class="sim-best-match-badge">Best</span> ';
			if ( counterSpan ) counterSpan.insertAdjacentHTML( 'afterbegin', starBadge );
		} else if ( next !== 0 && badge ) {
			badge.remove();
		}

		// Prev/next button states.
		const prevBtn = q( '.sim-carousel-prev', item );
		const nextBtn = q( '.sim-carousel-next', item );
		if ( prevBtn ) prevBtn.disabled = ( next === 0 );
		if ( nextBtn ) nextBtn.disabled = ( next === allMatches.length - 1 );

		// Preload adjacent images.
		if ( allMatches[ next + 1 ] ) preloadImage( allMatches[ next + 1 ].image_url );
		if ( allMatches[ next - 1 ] ) preloadImage( allMatches[ next - 1 ].image_url );
	}

	// -------------------------------------------------------------------------
	// Insert handlers
	// -------------------------------------------------------------------------

	async function handleInsertSingle( e ) {
		const btn  = e.currentTarget;
		const hash = btn.dataset.hash;
		const item = q( `.sim-match-item[data-hash="${ hash }"]` );
		if ( ! item ) return;
		const imageId = parseInt( item.dataset.imageId, 10 );

		btn.disabled = true;
		btn.textContent = 'Inserting…';
		showProgress( 'Inserting image&hellip;' );

		const result = await insertOne( hash, imageId );

		if ( result instanceof Error ) {
			showError( result.message );
			return;
		}

		showProgress( Icons.check ? Icons.check() + ' Image inserted. Reloading&hellip;' : 'Image inserted. Reloading&hellip;' );
		setTimeout( () => location.reload(), 800 );
	}

	async function handleInsertAll() {
		const items = document.querySelectorAll( '.sim-match-item' );
		const insertions = [];

		items.forEach( item => {
			const cb = q( '.sim-select-checkbox', item );
			if ( ! cb || ! cb.checked ) return;
			const hash    = item.dataset.hash;
			const imageId = parseInt( item.dataset.imageId, 10 );
			if ( hash && imageId ) insertions.push( { heading_hash: hash, image_id: imageId } );
		} );

		if ( insertions.length === 0 ) {
			alert( 'No images selected.' );
			return;
		}

		showProgress( `Inserting ${ insertions.length } image${ insertions.length !== 1 ? 's' : '' }…` );
		q( '.sim-insert-all-button', getModal() ).disabled = true;

		const result = await insertBatch( insertions );

		if ( result instanceof Error ) {
			showError( result.message );
			return;
		}

		const doneMsg = ( Icons.check ? Icons.check() + ' ' : '' ) +
			'Inserted ' + result.inserted + ' image' + ( result.inserted !== 1 ? 's' : '' ) + '. Reloading&hellip;';
		showProgress( doneMsg );
		setTimeout( () => location.reload(), 1000 );
	}

	// -------------------------------------------------------------------------
	// Event binding
	// -------------------------------------------------------------------------

	function bindEvents() {
		const el = getModal();
		if ( ! el ) return;

		// Open via the below-title button.
		document.addEventListener( 'click', e => {
			if ( e.target && e.target.id === 'sim-open-modal' ) openModal();
		} );

		// Close.
		el.addEventListener( 'click', e => {
			if (
				e.target.classList.contains( 'sim-modal-close' ) ||
				e.target.classList.contains( 'sim-cancel-button' ) ||
				e.target.classList.contains( 'sim-modal-overlay' )
			) closeModal();
		} );

		// Carousel prev/next.
		el.addEventListener( 'click', e => {
			if ( e.target.classList.contains( 'sim-carousel-prev' ) ) {
				navigateCarousel( e.target.dataset.hash, -1 );
			}
			if ( e.target.classList.contains( 'sim-carousel-next' ) ) {
				navigateCarousel( e.target.dataset.hash, 1 );
			}
		} );

		// Keyboard arrow navigation within modal.
		document.addEventListener( 'keydown', e => {
			if ( el.style.display === 'none' ) return;
			if ( e.key === 'ArrowLeft' )  el.querySelector( '.sim-carousel-prev:not([disabled])' )?.click();
			if ( e.key === 'ArrowRight' ) el.querySelector( '.sim-carousel-next:not([disabled])' )?.click();
			if ( e.key === 'Escape' )     closeModal();
		} );

		// Single insert.
		el.addEventListener( 'click', e => {
			if ( e.target.classList.contains( 'sim-insert-single-button' ) ) {
				handleInsertSingle( { currentTarget: e.target } );
			}
		} );

		// Insert all.
		const insertAllBtn = q( '.sim-insert-all-button', el );
		if ( insertAllBtn ) insertAllBtn.addEventListener( 'click', handleInsertAll );
	}

	// -------------------------------------------------------------------------
	// Init
	// -------------------------------------------------------------------------

	/**
	 * Mount the Classic Editor trigger below the title field.
	 * Never show it on block-editor screens (Gutenberg has its own UI).
	 */
	function mountClassicTrigger() {
		const container = document.getElementById( 'sim-editor-button-container' );
		if ( ! container ) {
			return;
		}

		// Block editor: keep hidden. Entry point is gutenberg.js sidebar/panel.
		if ( document.body.classList.contains( 'block-editor-page' ) ) {
			container.hidden = true;
			return;
		}

		const titleDiv = document.getElementById( 'titlediv' );
		if ( ! titleDiv || ! titleDiv.parentNode ) {
			// Classic chrome not ready yet — retry briefly, then give up quietly.
			let attempts = 0;
			const timer = window.setInterval( () => {
				attempts += 1;
				const readyTitle = document.getElementById( 'titlediv' );
				if ( readyTitle && readyTitle.parentNode ) {
					window.clearInterval( timer );
					readyTitle.parentNode.insertBefore( container, readyTitle.nextSibling );
					container.hidden = false;
					return;
				}
				if ( attempts >= 20 ) {
					window.clearInterval( timer );
				}
			}, 100 );
			return;
		}

		titleDiv.parentNode.insertBefore( container, titleDiv.nextSibling );
		container.hidden = false;
	}

	function init() {
		bindEvents();
		mountClassicTrigger();

		// Expose for Gutenberg sidebar button.
		window.simOpenModal = openModal;
		window.simCloseModal = closeModal;
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

} )();
