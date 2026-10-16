/**
 * dashboard.js — hourly runs chart and Dashboard actions.
 *
 * Data comes from smartImageMatcherDashboard (printed by admin/views/dashboard.php).
 * Inserted sits at the baseline with Review stacked on top; skipped hours are
 * hatched stubs, and a run of skipped hours gets a stall band.
 *
 * @package SmartImageMatcher
 * @since   3.7.0
 */

( function () {
	'use strict';

	const root = document.getElementById( 'sim-dash' );
	if ( ! root ) {
		return;
	}

	const config = window.smartImageMatcherDashboard || {};
	const SLOTS = Array.isArray( config.slots ) ? config.slots : [];
	const apiFetch = window.wp && window.wp.apiFetch;
	const wpI18n = ( window.wp && window.wp.i18n ) || {};
	const __ = wpI18n.__ || ( ( s ) => s );
	const _n = wpI18n._n || ( ( one, many, n ) => ( n === 1 ? one : many ) );
	const sprintf = wpI18n.sprintf || ( ( s, ...a ) => {
		let i = 0;
		return String( s ).replace( /%(\d+\$)?[sd]/g, () => String( a[ i++ ] ) );
	} );
	const NS = 'http://www.w3.org/2000/svg';
	const COLOR_INS = '#2271b1';
	const COLOR_REV = '#c2691a';

	if ( apiFetch && apiFetch.createNonceMiddleware && config.nonce ) {
		apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );
	}

	const $ = ( id ) => document.getElementById( id );
	const fmt = ( n ) => Number( n || 0 ).toLocaleString();

	// Safe in text and in quoted attributes.
	function esc( value ) {
		return String( value == null ? '' : value )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}

	function toast( message ) {
		const el = $( 'sim-dash-toast' );
		el.textContent = message;
		el.hidden = false;
		window.clearTimeout( toast.timer );
		toast.timer = window.setTimeout( () => {
			el.hidden = true;
		}, 3500 );
	}

	// ---------------------------------------------------------------------
	// Chart
	// ---------------------------------------------------------------------

	const svg = $( 'sim-dash-chart' );
	const tip = $( 'sim-dash-tip' );
	let range = 48;

	function el( name, attrs, text ) {
		const node = document.createElementNS( NS, name );
		Object.keys( attrs ).forEach( ( k ) => node.setAttribute( k, attrs[ k ] ) );
		if ( text != null ) node.textContent = text;
		return node;
	}

	function niceMax( v ) {
		const steps = [ 10, 20, 50, 100, 200, 250, 500, 1000, 2000, 2500, 5000 ];
		for ( const s of steps ) {
			if ( v <= s ) return s;
		}
		return Math.ceil( v / 1000 ) * 1000;
	}

	function barPath( x, top, w, h, r ) {
		r = Math.min( r, w / 2, h );
		if ( ! r ) return `M${ x },${ top }h${ w }v${ h }h${ -w }Z`;
		return `M${ x },${ top + h }V${ top + r }Q${ x },${ top } ${ x + r },${ top }H${ x + w - r }Q${ x + w },${ top } ${ x + w },${ top + r }V${ top + h }Z`;
	}

	function data() {
		return SLOTS.slice( -range );
	}

	function slotNote( s ) {
		if ( s.note ) return s.note;
		return s.state === 'none' ? __( 'No run this hour', 'smart-image-matcher' ) : '';
	}

	function drawChart() {
		if ( ! svg ) return;
		const rows = data();
		const W = svg.clientWidth || 700;
		const H = 240;
		const padL = 44;
		const padR = 8;
		const padT = 12;
		const padB = 26;
		svg.setAttribute( 'viewBox', `0 0 ${ W } ${ H }` );
		while ( svg.firstChild ) svg.removeChild( svg.firstChild );

		const max = niceMax( Math.max( 0, ...rows.map( ( s ) => s.ins + s.rev ) ) || 10 );
		const plotW = W - padL - padR;
		const plotH = H - padT - padB;
		const slotW = plotW / Math.max( 1, rows.length );
		const colW = Math.max( 3, Math.min( 18, slotW - 3 ) );
		const y = ( v ) => padT + plotH - ( v / max ) * plotH;

		const defs = el( 'defs', {} );
		const pat = el( 'pattern', { id: 'sim-hatch', width: 4, height: 4, patternUnits: 'userSpaceOnUse', patternTransform: 'rotate(45)' } );
		pat.appendChild( el( 'rect', { width: 4, height: 4, fill: '#f6f7f7' } ) );
		pat.appendChild( el( 'rect', { width: 2, height: 4, fill: '#c3c4c7' } ) );
		defs.appendChild( pat );
		svg.appendChild( defs );

		// Stall bands: runs of three or more skipped hours.
		let start = -1;
		const bands = [];
		rows.forEach( ( s, i ) => {
			if ( s.state === 'skip' ) {
				if ( start < 0 ) start = i;
			} else if ( start >= 0 ) {
				bands.push( [ start, i - 1 ] );
				start = -1;
			}
		} );
		if ( start >= 0 ) bands.push( [ start, rows.length - 1 ] );
		bands.filter( ( [ a, b ] ) => b - a >= 2 ).forEach( ( [ a, b ] ) => {
			const bx = padL + a * slotW;
			svg.appendChild( el( 'rect', { x: bx, y: padT, width: ( b - a + 1 ) * slotW, height: plotH, fill: '#fcf0f1' } ) );
			svg.appendChild( el( 'text', { x: bx + 6, y: padT + 14, fill: '#b32d2e', 'font-weight': '600' }, sprintf( _n( '%d h with no run', '%d h with no run', b - a + 1, 'smart-image-matcher' ), b - a + 1 ) ) );
		} );

		for ( let g = 0; g <= 4; g++ ) {
			const v = ( max * g ) / 4;
			const gy = y( v );
			svg.appendChild( el( 'line', { x1: padL, x2: W - padR, y1: gy, y2: gy, stroke: g === 0 ? '#c3c4c7' : '#ececed', 'stroke-width': 1 } ) );
			svg.appendChild( el( 'text', { x: padL - 6, y: gy + 4, 'text-anchor': 'end' }, fmt( v ) ) );
		}

		rows.forEach( ( s, i ) => {
			const cx = padL + i * slotW + ( slotW - colW ) / 2;
			const label = s.label + ': ' + slotNote( s ) + ( s.ins + s.rev ? ', ' + sprintf( __( '%1$d inserted, %2$d to Review', 'smart-image-matcher' ), s.ins, s.rev ) : '' );
			const grp = el( 'g', { 'data-i': i, tabindex: 0, role: 'img', 'aria-label': label } );
			if ( s.state === 'skip' ) {
				grp.appendChild( el( 'rect', { x: cx, y: y( 0 ) - 8, width: colW, height: 8, rx: 1.5, fill: 'url(#sim-hatch)', stroke: '#c3c4c7', 'stroke-width': 1 } ) );
			} else if ( s.ins + s.rev > 0 ) {
				const hIns = ( s.ins / max ) * plotH;
				const hRev = ( s.rev / max ) * plotH;
				const gap = hIns > 0 && hRev > 0 ? 2 : 0;
				if ( hIns > 0 ) grp.appendChild( el( 'path', { d: barPath( cx, y( 0 ) - hIns, colW, hIns, hRev > 0 ? 0 : 4 ), fill: COLOR_INS } ) );
				if ( hRev > 0 ) grp.appendChild( el( 'path', { d: barPath( cx, y( 0 ) - hIns - gap - hRev, colW, Math.max( 1, hRev ), 4 ), fill: COLOR_REV } ) );
				if ( s.state === 'live' ) {
					grp.appendChild( el( 'rect', { x: cx - 2, y: y( 0 ) - hIns - gap - hRev - 4, width: colW + 4, height: hIns + hRev + gap + 4, fill: 'none', stroke: COLOR_INS, 'stroke-dasharray': '3 2', rx: 3 } ) );
				}
			} else if ( s.state === 'ran' || s.state === 'live' ) {
				// Ran but nothing to insert: a thin baseline tick so it is not mistaken for "no run".
				grp.appendChild( el( 'rect', { x: cx, y: y( 0 ) - 2, width: colW, height: 2, fill: COLOR_INS, opacity: 0.5 } ) );
			}
			grp.appendChild( el( 'rect', { x: padL + i * slotW, y: padT, width: slotW, height: plotH, fill: 'transparent' } ) );
			svg.appendChild( grp );
		} );

		rows.forEach( ( s, i ) => {
			if ( s.hour % 6 !== 0 ) return;
			const tx = padL + i * slotW + slotW / 2;
			svg.appendChild( el( 'text', { x: tx, y: H - 8, 'text-anchor': 'middle', 'font-weight': s.hour === 0 ? '600' : '400' }, s.hour === 0 ? s.day : String( s.hour ).padStart( 2, '0' ) + ':00' ) );
		} );

		const ran = rows.filter( ( s ) => s.state === 'ran' || s.state === 'live' ).length;
		const skipped = rows.filter( ( s ) => s.state === 'skip' ).length;
		const ins = rows.reduce( ( a, s ) => a + s.ins, 0 );
		const summary = sprintf( __( 'Last %1$d h: %2$d runs · %3$d skipped · %4$s images inserted', 'smart-image-matcher' ), range, ran, skipped, fmt( ins ) );
		$( 'sim-dash-chart-sum' ).textContent = summary;
		$( 'sim-dash-chart-desc' ).textContent = summary;
		drawTable( rows );
	}

	function showTip( i, x, yPos ) {
		const s = data()[ i ];
		if ( ! s ) return;
		let html = `<b>${ esc( s.label ) }</b><div>${ esc( slotNote( s ) ) }</div>`;
		if ( s.ins + s.rev ) {
			html += `<div class="sim-dash-tip-row"><span><i style="background:${ COLOR_INS }"></i>${ esc( __( 'Inserted', 'smart-image-matcher' ) ) }</span><b>${ fmt( s.ins ) }</b></div>`;
			html += `<div class="sim-dash-tip-row"><span><i style="background:${ COLOR_REV }"></i>${ esc( __( 'Sent to Review', 'smart-image-matcher' ) ) }</span><b>${ fmt( s.rev ) }</b></div>`;
		}
		tip.innerHTML = html;
		tip.hidden = false;
		const wrap = $( 'sim-dash-chart-wrap' ).getBoundingClientRect();
		let left = x - wrap.left + 12;
		left = Math.min( left, wrap.width - tip.offsetWidth - 8 );
		tip.style.left = Math.max( 8, left ) + 'px';
		tip.style.top = Math.max( 4, yPos - wrap.top - 10 ) + 'px';
	}

	function drawTable( rows ) {
		const list = rows.slice().reverse().filter( ( s ) => s.state !== 'none' || s.note );
		$( 'sim-dash-chart-table' ).innerHTML = list.length
			? `<table class="sim-dash-t"><thead><tr><th>${ esc( __( 'Hour', 'smart-image-matcher' ) ) }</th><th>${ esc( __( 'Outcome', 'smart-image-matcher' ) ) }</th><th class="sim-dash-n">${ esc( __( 'Inserted', 'smart-image-matcher' ) ) }</th><th class="sim-dash-n">${ esc( __( 'Sent to Review', 'smart-image-matcher' ) ) }</th></tr></thead><tbody>` +
				list.map( ( s ) => `<tr><td>${ esc( s.label ) }</td><td>${ esc( slotNote( s ) ) }</td><td class="sim-dash-n">${ fmt( s.ins ) }</td><td class="sim-dash-n">${ fmt( s.rev ) }</td></tr>` ).join( '' ) +
				'</tbody></table>'
			: `<p class="sim-dash-empty">${ esc( __( 'Nothing ran in this period.', 'smart-image-matcher' ) ) }</p>`;
	}

	if ( svg ) {
		svg.addEventListener( 'mousemove', ( e ) => {
			const g = e.target.closest( 'g[data-i]' );
			if ( g ) showTip( parseInt( g.dataset.i, 10 ), e.clientX, e.clientY );
			else tip.hidden = true;
		} );
		svg.addEventListener( 'mouseleave', () => {
			tip.hidden = true;
		} );
		svg.addEventListener( 'focusin', ( e ) => {
			const g = e.target.closest( 'g[data-i]' );
			if ( ! g ) return;
			const r = g.getBoundingClientRect();
			showTip( parseInt( g.dataset.i, 10 ), r.left, r.top );
		} );
		svg.addEventListener( 'focusout', () => {
			tip.hidden = true;
		} );

		$( 'sim-dash-toggle-table' ).addEventListener( 'click', ( e ) => {
			const table = $( 'sim-dash-chart-table' );
			const open = table.hidden;
			table.hidden = ! open;
			e.currentTarget.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			e.currentTarget.textContent = open ? __( 'Hide table', 'smart-image-matcher' ) : __( 'View as table', 'smart-image-matcher' );
		} );

		root.querySelectorAll( '[data-range]' ).forEach( ( b ) => b.addEventListener( 'click', () => {
			range = parseInt( b.dataset.range, 10 );
			root.querySelectorAll( '[data-range]' ).forEach( ( x ) => x.setAttribute( 'aria-pressed', x === b ? 'true' : 'false' ) );
			drawChart();
		} ) );

		let resizeTimer = null;
		window.addEventListener( 'resize', () => {
			window.clearTimeout( resizeTimer );
			resizeTimer = window.setTimeout( drawChart, 120 );
		} );

		drawChart();
	}

	// ---------------------------------------------------------------------
	// Attention + errors
	// ---------------------------------------------------------------------

	function post( path, body ) {
		return apiFetch( { path: '/smart-image-matcher/v1' + path, method: 'POST', data: body || {} } );
	}

	function recount() {
		const attn = root.querySelectorAll( '#sim-dash-attn-list [data-key]' ).length;
		$( 'sim-dash-attn-count' ).textContent = String( attn );
		$( 'sim-dash-attn-empty' ).hidden = attn > 0;
		const errs = root.querySelectorAll( '#sim-dash-errors [data-message]' ).length;
		$( 'sim-dash-err-empty' ).hidden = errs > 0;
		const clear = $( 'sim-dash-clear-errors' );
		if ( clear ) clear.hidden = ! errs;
	}

	if ( ! apiFetch ) {
		return;
	}

	$( 'sim-dash-attn-list' ).addEventListener( 'click', async ( e ) => {
		const item = e.target.closest( '[data-key]' );
		if ( ! item ) return;
		const requeue = e.target.closest( '[data-requeue]' );
		if ( requeue ) {
			requeue.disabled = true;
			try {
				const res = await post( '/jobs/' + encodeURIComponent( requeue.dataset.requeue ) + '/requeue-lost' );
				const n = parseInt( res.queued || 0, 10 );
				toast( n ? sprintf( _n( 'Queued %d article again.', 'Queued %d articles again.', n, 'smart-image-matcher' ), n ) : __( 'No failed articles found for this run. They may have been removed from the queue.', 'smart-image-matcher' ) );
				if ( n ) {
					await post( '/dashboard/attention/dismiss', { key: item.dataset.key } );
					item.remove();
					recount();
				}
			} catch ( err ) {
				toast( ( err && err.message ) || __( 'Could not re-queue.', 'smart-image-matcher' ) );
			}
			requeue.disabled = false;
			return;
		}
		if ( e.target.closest( '[data-dismiss-attn]' ) ) {
			try {
				await post( '/dashboard/attention/dismiss', { key: item.dataset.key } );
				item.remove();
				recount();
				toast( __( 'Dismissed.', 'smart-image-matcher' ) );
			} catch ( err ) {
				toast( ( err && err.message ) || __( 'Could not dismiss.', 'smart-image-matcher' ) );
			}
		}
	} );

	$( 'sim-dash-errors' ).addEventListener( 'click', async ( e ) => {
		if ( ! e.target.closest( '[data-dismiss-error]' ) ) return;
		const group = e.target.closest( '[data-message]' );
		try {
			await post( '/dashboard/errors/dismiss', { message: group.dataset.message } );
			group.remove();
			recount();
			toast( __( 'Dismissed.', 'smart-image-matcher' ) );
		} catch ( err ) {
			toast( ( err && err.message ) || __( 'Could not dismiss.', 'smart-image-matcher' ) );
		}
	} );

	const clear = $( 'sim-dash-clear-errors' );
	if ( clear ) {
		clear.addEventListener( 'click', async () => {
			if ( ! window.confirm( __( 'Remove every stored error?', 'smart-image-matcher' ) ) ) return;
			try {
				await post( '/dashboard/errors/clear' );
				root.querySelectorAll( '#sim-dash-errors [data-message]' ).forEach( ( g ) => g.remove() );
				recount();
				toast( __( 'Errors cleared.', 'smart-image-matcher' ) );
			} catch ( err ) {
				toast( ( err && err.message ) || __( 'Could not clear.', 'smart-image-matcher' ) );
			}
		} );
	}
}() );
