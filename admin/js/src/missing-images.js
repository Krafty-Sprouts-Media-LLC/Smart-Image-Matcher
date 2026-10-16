/**
 * Missing Images page: headings without an image, grouped by article.
 *
 * Reads /missing-images (index kept in post meta), copies headings in three
 * formats, links out to image searches, inserts library matches and hides
 * headings that need no image.
 *
 * @package SmartImageMatcher
 * @since   3.6.0
 */
( function () {
	'use strict';

	const cfg = window.smartImageMatcherMissing || {};
	const t = cfg.i18n || {};
	const api = window.wp && window.wp.apiFetch;
	const BASE = cfg.restPath || '/smart-image-matcher/v1';
	const PER_PAGE = 25;
	const STORE_KEY = 'sim-missing-copy-as';

	const $ = ( id ) => document.getElementById( id );
	const app = $( 'sim-missing-app' );
	if ( ! app || ! api ) {
		return;
	}

	const state = {
		page: 1,
		pages: 1,
		total: 0,
		items: [],
		open: new Set(),
		picked: new Map(), // id → item (kept across pages)
		lib: new Map(), // "postId:hash" → { loading, images, error }
	};

	const esc = ( s ) => String( s ?? '' ).replace( /[&<>"']/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ] ) );
	const fmtNum = ( n ) => Number( n || 0 ).toLocaleString();

	/* ---------- Filters ---------- */

	function filters() {
		return {
			search: $( 'sim-mi-search' ).value.trim(),
			numbered: $( 'sim-mi-numbered' ).checked ? 1 : 0,
			no_images: $( 'sim-mi-noimg' ).checked ? 1 : 0,
			status: $( 'sim-mi-status' ).value,
			post_type: $( 'sim-mi-type' ).value,
			category: $( 'sim-mi-cat' ).value,
			sort: $( 'sim-mi-sort' ).value,
		};
	}

	function query( params ) {
		const q = new URLSearchParams();
		Object.keys( params ).forEach( ( k ) => {
			if ( params[ k ] !== '' && params[ k ] !== undefined ) {
				q.set( k, params[ k ] );
			}
		} );
		return q.toString();
	}

	( cfg.statuses || [] ).forEach( ( s ) => $( 'sim-mi-status' ).add( new Option( s.label, s.value ) ) );
	( cfg.categories || [] ).forEach( ( c ) => $( 'sim-mi-cat' ).add( new Option( c.name, String( c.id ) ) ) );

	/* ---------- Load ---------- */

	let loadSeq = 0;
	async function load( keepPage ) {
		if ( ! keepPage ) {
			state.page = 1;
		}
		const seq = ++loadSeq;
		try {
			const res = await api( { path: `${ BASE }/missing-images?${ query( { ...filters(), page: state.page, per_page: PER_PAGE } ) }` } );
			if ( seq !== loadSeq ) {
				return;
			}
			state.items = res.items || [];
			state.total = res.total || 0;
			state.pages = Math.max( 1, res.pages || 1 );
			if ( state.items.length && ! state.open.size ) {
				state.open.add( state.items[ 0 ].id );
			}
			renderSummary( res.summary || {} );
			render();
		} catch ( e ) {
			$( 'sim-mi-list' ).innerHTML = `<p class="sim-mi-empty">${ esc( t.loadFailed ) }</p>`;
		}
	}

	let pollTimer = null;
	function renderSummary( s ) {
		$( 'sim-mi-k-articles' ).textContent = fmtNum( s.articles );
		$( 'sim-mi-k-headings' ).textContent = fmtNum( s.headings );
		$( 'sim-mi-k-numbered' ).textContent = fmtNum( s.numbered );
		$( 'sim-mi-k-ignored' ).textContent = fmtNum( s.ignored );
		$( 'sim-mi-k-noimg' ).textContent = `${ fmtNum( s.no_images ) } with no images at all`;

		const scan = $( 'sim-mi-scan' );
		const scanning = !! s.scanning || ( s.eligible && s.indexed < s.eligible );
		scan.hidden = ! scanning;
		if ( scanning ) {
			scan.querySelector( 'p' ).textContent = ( t.scanning || '' ).replace( '%1$s', fmtNum( s.indexed ) ).replace( '%2$s', fmtNum( s.eligible ) );
		}
		clearTimeout( pollTimer );
		if ( scanning ) {
			pollTimer = setTimeout( () => load( true ), 15000 );
		}
	}

	/* ---------- Render ---------- */

	function searchLinks( head ) {
		const full = head.phrase || head.text;
		const subject = full.split( /\s+in\s+/i )[ 0 ];
		const links = [
			[ 'Google Images', `https://www.google.com/search?tbm=isch&q=${ encodeURIComponent( full ) }` ],
			[ 'Unsplash', `https://unsplash.com/s/photos/${ encodeURIComponent( subject.toLowerCase().replace( /\s+/g, '-' ) ) }` ],
			[ 'Pexels', `https://www.pexels.com/search/${ encodeURIComponent( subject ) }/` ],
		];
		return links.map( ( l ) => `<a class="sim-mi-link" href="${ esc( l[ 1 ] ) }" target="_blank" rel="noopener noreferrer">${ l[ 0 ] } ↗</a>` ).join( '' );
	}

	function libPanel( item, head ) {
		const entry = state.lib.get( `${ item.id }:${ head.hash }` );
		if ( ! entry ) {
			return '';
		}
		if ( entry.loading ) {
			return '<div class="sim-mi-lib"><span class="description">Searching your library…</span></div>';
		}
		if ( entry.error || ! entry.images.length ) {
			return `<div class="sim-mi-lib"><span class="description">${ esc( entry.error || t.noLibrary ) }</span></div>`;
		}
		return `<div class="sim-mi-lib"><div class="sim-mi-lib-grid">${ entry.images.map( ( img ) => `
			<div class="sim-mi-lib-item">
				${ img.thumb ? `<img src="${ esc( img.thumb ) }" alt="" loading="lazy" />` : '<span class="sim-mi-noimg"></span>' }
				<code>${ esc( img.filename ) }</code>
				<button type="button" class="button button-small" data-use="${ item.id }" data-hash="${ esc( head.hash ) }" data-image="${ img.id }">Insert under heading</button>
			</div>` ).join( '' ) }</div></div>`;
	}

	function render() {
		const host = $( 'sim-mi-list' );
		const f = filters();
		const termQ = f.search && ! /^\d+$/.test( f.search ) ? f.search.toLowerCase() : '';
		let headCount = 0;

		if ( ! state.items.length ) {
			host.innerHTML = `<div class="sim-mi-empty"><strong>${ esc( t.nothing ) }</strong><br />${ esc( t.nothingHint ) }</div>`;
		} else {
			host.innerHTML = state.items.map( ( item ) => {
				// A search that matches heading text (not the title) narrows the headings shown.
				let heads = item.heads;
				if ( termQ && item.title.toLowerCase().indexOf( termQ ) === -1 ) {
					const hit = heads.filter( ( h ) => h.text.toLowerCase().indexOf( termQ ) > -1 );
					heads = hit.length ? hit : heads;
				}
				headCount += heads.length;
				const have = Math.max( 0, item.total - item.missing );
				const open = state.open.has( item.id );
				return `<div class="sim-mi-article${ open ? ' is-open' : '' }">
					<div class="sim-mi-row">
						<div class="sim-mi-row-main">
							<button type="button" class="sim-mi-title" data-toggle="${ item.id }" aria-expanded="${ open }"><span class="sim-mi-caret" aria-hidden="true">▶</span><span>${ esc( item.title ) }</span></button>
							<div class="sim-mi-sub">
								<span>#${ item.id } · ${ esc( item.status ) }${ item.category ? ' · ' + esc( item.category ) : '' }</span>
								${ item.all_missing ? '<span class="sim-mi-none">No images at all</span>' : `<span>${ item.missing } of ${ item.total } headings missing</span>` }
								<span class="sim-mi-cov" title="${ have } of ${ item.total } headings have an image"><i style="width:${ item.total ? ( have / item.total ) * 100 : 0 }%"></i></span>
							</div>
						</div>
						<div class="sim-mi-row-acts">
							<label class="sim-mi-toggle"><input type="checkbox" data-pick="${ item.id }"${ state.picked.has( item.id ) ? ' checked' : '' } />Select</label>
							<button type="button" class="button button-primary button-small" data-copy-art="${ item.id }">Copy ${ heads.length } heading${ heads.length === 1 ? '' : 's' }</button>
							${ item.edit_url ? `<a class="button button-small" href="${ esc( item.edit_url ) }">Edit article</a>` : '' }
						</div>
					</div>
					${ open ? `<div class="sim-mi-heads">${ heads.map( ( h ) => `
						<div class="sim-mi-head-row">
							<div class="sim-mi-head-main">
								<div class="sim-mi-head-text"><span class="sim-mi-lvl">H${ h.level }</span>${ esc( h.text ) }${ h.numbered ? '<span class="sim-mi-badge">Numbered</span>' : '' }</div>
								<div class="sim-mi-phrase">Search phrase <code>${ esc( h.phrase ) }</code><button type="button" class="button-link" data-copy="${ esc( h.phrase ) }">Copy</button><span class="sim-mi-sep">Search:</span>${ searchLinks( h ) }</div>
							</div>
							<div class="sim-mi-head-acts">
								<button type="button" class="button button-small" data-copy="${ esc( h.text ) }">Copy heading</button>
								<button type="button" class="button button-small" data-lib="${ item.id }" data-hash="${ esc( h.hash ) }">Search library</button>
								<button type="button" class="button-link sim-mi-skip" data-skip="${ item.id }" data-hash="${ esc( h.hash ) }" title="Hide this heading: it doesn’t need an image">Not needed</button>
							</div>
							${ libPanel( item, h ) }
						</div>` ).join( '' ) }</div>` : '' }
				</div>`;
			} ).join( '' );
		}

		$( 'sim-mi-meta-text' ).innerHTML = `<strong>${ fmtNum( state.total ) }</strong> articles match · <strong>${ fmtNum( headCount ) }</strong> headings on this page`;
		$( 'sim-mi-pager' ).hidden = state.pages <= 1;
		$( 'sim-mi-pager-text' ).textContent = `Page ${ state.page } of ${ state.pages } · ${ PER_PAGE } articles per page`;
		$( 'sim-mi-prev' ).disabled = state.page <= 1;
		$( 'sim-mi-next' ).disabled = state.page >= state.pages;
		updatePicked();
	}

	/* ---------- Copy formats ---------- */

	function copyAs() {
		return $( 'sim-mi-copy-as' ).value;
	}
	try {
		const saved = window.localStorage.getItem( STORE_KEY );
		if ( saved ) {
			$( 'sim-mi-copy-as' ).value = saved;
		}
	} catch ( e ) { /* storage unavailable */ }
	$( 'sim-mi-copy-as' ).addEventListener( 'change', function () {
		try {
			window.localStorage.setItem( STORE_KEY, this.value );
		} catch ( e ) { /* ignore */ }
	} );

	/** items: [{ title, heads }]. Phrases and headings are de-duplicated; grouped keeps every heading. */
	function buildText( items, fmt ) {
		if ( fmt === 'grouped' ) {
			return items.map( ( i ) => `${ i.title }\n${ i.heads.map( ( h ) => `- ${ h.text }` ).join( '\n' ) }` ).join( '\n\n' );
		}
		const seen = new Set();
		const out = [];
		items.forEach( ( i ) => i.heads.forEach( ( h ) => {
			const line = fmt === 'asis' ? h.text : ( h.phrase || h.text );
			if ( ! seen.has( line.toLowerCase() ) ) {
				seen.add( line.toLowerCase() );
				out.push( line );
			}
		} ) );
		return out.join( '\n' );
	}
	const headTotal = ( items ) => items.reduce( ( n, i ) => n + i.heads.length, 0 );

	async function copyText( text, msg ) {
		try {
			await navigator.clipboard.writeText( text );
			toast( msg );
		} catch ( e ) {
			const ta = document.createElement( 'textarea' );
			ta.value = text;
			document.body.appendChild( ta );
			ta.select();
			let ok = false;
			try {
				ok = document.execCommand( 'copy' );
			} catch ( e2 ) { /* fall through */ }
			ta.remove();
			toast( ok ? msg : t.copyFailed );
		}
	}

	async function fetchAll() {
		const res = await api( { path: `${ BASE }/missing-images/all?${ query( filters() ) }` } );
		if ( res.capped ) {
			toast( t.capped );
		}
		return res.items || [];
	}

	$( 'sim-mi-copy-all' ).addEventListener( 'click', async () => {
		try {
			const items = await fetchAll();
			copyText( buildText( items, copyAs() ), `Copied ${ fmtNum( headTotal( items ) ) } headings from ${ fmtNum( items.length ) } articles` );
		} catch ( e ) {
			toast( t.loadFailed );
		}
	} );

	/* ---------- Selection ---------- */

	function updatePicked() {
		const n = state.picked.size;
		const btn = $( 'sim-mi-copy-picked' );
		btn.hidden = ! n;
		btn.textContent = `Copy ${ n } selected article${ n === 1 ? '' : 's' }`;
	}
	$( 'sim-mi-copy-picked' ).addEventListener( 'click', () => {
		const items = [ ...state.picked.values() ];
		copyText( buildText( items, copyAs() ), `Copied ${ headTotal( items ) } headings from ${ items.length } article${ items.length === 1 ? '' : 's' }` );
	} );

	/* ---------- Preview ---------- */

	let previewItems = [];
	let previewFmt = 'phrase';
	function syncFmt() {
		app.querySelectorAll( '[data-fmt]' ).forEach( ( b ) => b.setAttribute( 'aria-pressed', b.dataset.fmt === previewFmt ? 'true' : 'false' ) );
		$( 'sim-mi-out' ).value = buildText( previewItems, previewFmt );
	}
	$( 'sim-mi-preview' ).addEventListener( 'click', async () => {
		try {
			previewItems = state.picked.size ? [ ...state.picked.values() ] : await fetchAll();
		} catch ( e ) {
			toast( t.loadFailed );
			return;
		}
		previewFmt = copyAs();
		$( 'sim-mi-modal-desc' ).textContent = state.picked.size
			? `The ${ state.picked.size } selected article(s).`
			: 'Everything the filters show.';
		syncFmt();
		$( 'sim-mi-modal' ).hidden = false;
		$( 'sim-mi-modal-copy' ).focus();
	} );
	app.querySelectorAll( '[data-fmt]' ).forEach( ( b ) => b.addEventListener( 'click', () => {
		previewFmt = b.dataset.fmt;
		syncFmt();
	} ) );
	$( 'sim-mi-modal-close' ).addEventListener( 'click', () => ( $( 'sim-mi-modal' ).hidden = true ) );
	$( 'sim-mi-modal' ).addEventListener( 'click', function ( e ) {
		if ( e.target === this ) {
			this.hidden = true;
		}
	} );
	document.addEventListener( 'keydown', ( e ) => {
		if ( e.key === 'Escape' ) {
			$( 'sim-mi-modal' ).hidden = true;
		}
	} );
	$( 'sim-mi-modal-copy' ).addEventListener( 'click', () => copyText( $( 'sim-mi-out' ).value, t.copied ) );

	/* ---------- List actions ---------- */

	const itemById = ( id ) => state.items.find( ( i ) => String( i.id ) === String( id ) );

	function dropHead( item, hash ) {
		item.heads = item.heads.filter( ( h ) => h.hash !== hash );
		item.missing = Math.max( 0, item.missing - 1 );
		item.all_missing = false;
		if ( ! item.heads.length ) {
			state.items = state.items.filter( ( i ) => i !== item );
			state.picked.delete( item.id );
		} else if ( state.picked.has( item.id ) ) {
			state.picked.set( item.id, item );
		}
	}

	$( 'sim-mi-list' ).addEventListener( 'change', ( e ) => {
		const id = e.target.dataset && e.target.dataset.pick;
		if ( ! id ) {
			return;
		}
		const item = itemById( id );
		if ( e.target.checked && item ) {
			state.picked.set( item.id, item );
		} else {
			state.picked.delete( Number( id ) );
		}
		updatePicked();
	} );

	$( 'sim-mi-list' ).addEventListener( 'click', async ( e ) => {
		let b;
		if ( ( b = e.target.closest( '[data-toggle]' ) ) ) {
			const id = Number( b.dataset.toggle );
			state.open.has( id ) ? state.open.delete( id ) : state.open.add( id );
			render();
			return;
		}
		if ( ( b = e.target.closest( '[data-copy]' ) ) ) {
			copyText( b.dataset.copy, `${ t.copied }: “${ b.dataset.copy.slice( 0, 50 ) }”` );
			return;
		}
		if ( ( b = e.target.closest( '[data-copy-art]' ) ) ) {
			const item = itemById( b.dataset.copyArt );
			copyText( buildText( [ item ], copyAs() ), `Copied ${ item.heads.length } heading${ item.heads.length === 1 ? '' : 's' } from this article` );
			return;
		}
		if ( ( b = e.target.closest( '[data-lib]' ) ) ) {
			const key = `${ b.dataset.lib }:${ b.dataset.hash }`;
			if ( state.lib.has( key ) ) {
				state.lib.delete( key );
				render();
				return;
			}
			state.lib.set( key, { loading: true, images: [] } );
			render();
			try {
				const res = await api( { path: `${ BASE }/missing-images/library?${ query( { post_id: b.dataset.lib, heading_hash: b.dataset.hash } ) }` } );
				state.lib.set( key, { loading: false, images: res.images || [] } );
			} catch ( err ) {
				state.lib.set( key, { loading: false, images: [], error: ( err && err.message ) || t.loadFailed } );
			}
			render();
			return;
		}
		if ( ( b = e.target.closest( '[data-use]' ) ) ) {
			b.disabled = true;
			try {
				await api( {
					path: `${ BASE }/posts/${ b.dataset.use }/insert`,
					method: 'POST',
					data: { heading_hash: b.dataset.hash, image_id: Number( b.dataset.image ) },
				} );
				const item = itemById( b.dataset.use );
				state.lib.delete( `${ b.dataset.use }:${ b.dataset.hash }` );
				if ( item ) {
					dropHead( item, b.dataset.hash );
				}
				render();
				toast( t.inserted );
			} catch ( err ) {
				b.disabled = false;
				toast( ( err && err.message ) || t.loadFailed );
			}
			return;
		}
		if ( ( b = e.target.closest( '[data-skip]' ) ) ) {
			const postId = Number( b.dataset.skip );
			const hash = b.dataset.hash;
			try {
				await api( { path: `${ BASE }/missing-images/ignore`, method: 'POST', data: { post_id: postId, heading_hash: hash, ignored: true } } );
				const item = itemById( postId );
				if ( item ) {
					dropHead( item, hash );
				}
				render();
				toast( t.hidden, async () => {
					await api( { path: `${ BASE }/missing-images/ignore`, method: 'POST', data: { post_id: postId, heading_hash: hash, ignored: false } } );
					load( true );
				} );
			} catch ( err ) {
				toast( ( err && err.message ) || t.loadFailed );
			}
		}
	} );

	$( 'sim-mi-expand' ).addEventListener( 'click', () => {
		state.items.forEach( ( i ) => state.open.add( i.id ) );
		render();
	} );
	$( 'sim-mi-collapse' ).addEventListener( 'click', () => {
		state.open.clear();
		render();
	} );
	$( 'sim-mi-rescan' ).addEventListener( 'click', async () => {
		try {
			await api( { path: `${ BASE }/missing-images/rescan`, method: 'POST' } );
			toast( t.rescanStarted );
			load( true );
		} catch ( err ) {
			toast( ( err && err.message ) || t.loadFailed );
		}
	} );
	$( 'sim-mi-prev' ).addEventListener( 'click', () => {
		state.page = Math.max( 1, state.page - 1 );
		load( true );
	} );
	$( 'sim-mi-next' ).addEventListener( 'click', () => {
		state.page = Math.min( state.pages, state.page + 1 );
		load( true );
	} );

	let searchTimer = null;
	$( 'sim-mi-search' ).addEventListener( 'input', () => {
		clearTimeout( searchTimer );
		searchTimer = setTimeout( () => load( false ), 350 );
	} );
	[ 'sim-mi-numbered', 'sim-mi-noimg', 'sim-mi-status', 'sim-mi-type', 'sim-mi-cat', 'sim-mi-sort' ].forEach( ( id ) => $( id ).addEventListener( 'change', () => load( false ) ) );

	/* ---------- Toast ---------- */

	let toastTimer = null;
	function toast( msg, undo ) {
		const box = $( 'sim-mi-toast' );
		const btn = box.querySelector( 'button' );
		box.querySelector( 'span' ).textContent = msg;
		btn.hidden = ! undo;
		btn.onclick = undo
			? () => {
				box.hidden = true;
				undo();
			}
			: null;
		box.hidden = false;
		clearTimeout( toastTimer );
		toastTimer = setTimeout( () => ( box.hidden = true ), 5000 );
	}

	load( false );
}() );
