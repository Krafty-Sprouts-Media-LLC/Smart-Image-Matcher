/**
 * Exclusion list editor — add one filename, scroll the list, remove rows.
 *
 * Replaces a growing textarea on Settings and Featured Images.
 *
 * @package SmartImageMatcher
 * @since   3.4.3
 */

( function () {
	'use strict';

	/**
	 * @param {HTMLElement} root Editor root.
	 */
	function bindEditor( root ) {
		const input = root.querySelector( '.sim-exclude-editor-input' );
		const addBtn = root.querySelector( '.sim-exclude-editor-add-btn' );
		const list = root.querySelector( '.sim-exclude-editor-list' );
		const empty = root.querySelector( '.sim-exclude-editor-empty' );
		const textarea = root.querySelector( '.sim-exclude-editor-value' );
		const removeLabel = root.getAttribute( 'data-remove' ) || 'Remove';

		if ( ! input || ! addBtn || ! list || ! textarea ) {
			return;
		}

		/**
		 * @return {string[]}
		 */
		function currentValues() {
			return Array.prototype.map.call(
				list.querySelectorAll( '[data-value]' ),
				function ( item ) {
					return item.getAttribute( 'data-value' ) || '';
				}
			).filter( Boolean );
		}

		function syncTextarea() {
			const values = currentValues();
			textarea.value = values.join( '\n' );
			if ( empty ) {
				empty.hidden = values.length > 0;
			}
			list.hidden = 0 === values.length;
			textarea.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}

		/**
		 * @param {string} value Filename or URL.
		 * @return {HTMLLIElement}
		 */
		function createRow( value ) {
			const li = document.createElement( 'li' );
			li.className = 'sim-exclude-editor-item';
			li.setAttribute( 'data-value', value );

			const name = document.createElement( 'code' );
			name.textContent = value;

			const btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'button-link sim-exclude-editor-remove';
			btn.textContent = removeLabel;
			btn.addEventListener( 'click', function () {
				li.remove();
				syncTextarea();
			} );

			li.appendChild( name );
			li.appendChild( btn );
			return li;
		}

		/**
		 * @param {string} raw Raw add field value.
		 */
		function addFromInput( raw ) {
			const parts = String( raw || '' ).split( /[\n,]+/ );
			const existing = currentValues().map( function ( item ) {
				return item.toLowerCase();
			} );

			parts.forEach( function ( part ) {
				const value = part.trim();
				if ( ! value ) {
					return;
				}
				if ( -1 !== existing.indexOf( value.toLowerCase() ) ) {
					return;
				}
				existing.push( value.toLowerCase() );
				list.appendChild( createRow( value ) );
			} );

			syncTextarea();
			input.value = '';
			input.focus();
		}

		function rebuildFromTextarea() {
			const parts = String( textarea.value || '' ).split( /[\n,]+/ );
			list.textContent = '';
			parts.forEach( function ( part ) {
				const value = part.trim();
				if ( value ) {
					list.appendChild( createRow( value ) );
				}
			} );
			syncTextarea();
		}

		list.querySelectorAll( '.sim-exclude-editor-remove' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				const row = btn.closest( '[data-value]' );
				if ( row ) {
					row.remove();
					syncTextarea();
				}
			} );
		} );

		addBtn.addEventListener( 'click', function () {
			addFromInput( input.value );
		} );

		input.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key ) {
				event.preventDefault();
				addFromInput( input.value );
			}
		} );

		textarea.addEventListener( 'sim-exclude-refresh', rebuildFromTextarea );
	}

	function init() {
		document.querySelectorAll( '[data-sim-exclude-editor]' ).forEach( bindEditor );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
