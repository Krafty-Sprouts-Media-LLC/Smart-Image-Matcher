/**
 * svg-icons.js — Custom SVG icon library for Smart Image Matcher.
 *
 * All icons are inline SVG strings returned as functions.
 * They replace emoji and dashicons throughout the plugin UI.
 *
 * Usage:
 *   import * as SimIcons from './svg-icons.js';
 *   element.innerHTML = SimIcons.check();
 *
 * Or via the global (when not using a bundler):
 *   window.SimIcons.check()
 *
 * @package SmartImageMatcher
 * @since   3.0.0
 */

( function ( root, factory ) {
	if ( typeof module === 'object' && module.exports ) {
		module.exports = factory();           // CommonJS / Node
	} else {
		root.SimIcons = factory();            // browser global
	}
} )( typeof self !== 'undefined' ? self : this, function () {

	/**
	 * Build an SVG string with consistent defaults.
	 *
	 * @param {string} paths  Inner SVG markup (path, circle, etc.)
	 * @param {string} [cls]  Optional extra CSS class.
	 * @param {string} [size] Width & height. Defaults to "16".
	 * @returns {string}
	 */
	function svg( paths, cls, size ) {
		size = size || '16';
		cls  = cls ? ' ' + cls : '';
		return (
			'<svg class="sim-icon' + cls + '" width="' + size + '" height="' + size + '"' +
			' viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"' +
			' aria-hidden="true" focusable="false">' +
			paths +
			'</svg>'
		);
	}

	return {

		/**
		 * Check / success mark (used when a heading has a match or an image is selected).
		 *
		 * @param {string} [cls] Extra CSS class.
		 * @returns {string}
		 */
		check: function ( cls ) {
			return svg(
				'<path d="M13.5 4.5L6 12L2.5 8.5"' +
				' stroke="currentColor" stroke-width="2"' +
				' stroke-linecap="round" stroke-linejoin="round"/>',
				'sim-icon-check' + ( cls ? ' ' + cls : '' )
			);
		},

		/**
		 * Cross / no-match (used when a heading has no match or an image is deselected).
		 *
		 * @param {string} [cls] Extra CSS class.
		 * @returns {string}
		 */
		cross: function ( cls ) {
			return svg(
				'<path d="M12 4L4 12M4 4L12 12"' +
				' stroke="currentColor" stroke-width="2"' +
				' stroke-linecap="round" stroke-linejoin="round"/>',
				'sim-icon-cross' + ( cls ? ' ' + cls : '' )
			);
		},

		/**
		 * Warning triangle (used for no-match state and review notices).
		 *
		 * @param {string} [cls] Extra CSS class.
		 * @returns {string}
		 */
		warning: function ( cls ) {
			return svg(
				'<path d="M8 1L15 14H1L8 1Z"' +
				' stroke="currentColor" stroke-width="1.5"' +
				' stroke-linecap="round" stroke-linejoin="round"/>' +
				'<path d="M8 6V9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>' +
				'<circle cx="8" cy="11" r="1" fill="currentColor"/>',
				'sim-icon-warning' + ( cls ? ' ' + cls : '' )
			);
		},

		/**
		 * Star / best-match badge (used for the top-ranked match in the carousel).
		 *
		 * @param {string} [cls] Extra CSS class.
		 * @returns {string}
		 */
		star: function ( cls ) {
			return svg(
				'<path d="M8 1.5L10 6H14.5L11 9L12.5 13.5L8 11L3.5 13.5L5 9L1.5 6H6L8 1.5Z"' +
				' stroke="currentColor" stroke-width="1.5"' +
				' stroke-linecap="round" stroke-linejoin="round"/>',
				'sim-icon-star' + ( cls ? ' ' + cls : '' )
			);
		},

		/**
		 * Chevron left (carousel prev button).
		 *
		 * @param {string} [cls] Extra CSS class.
		 * @returns {string}
		 */
		chevronLeft: function ( cls ) {
			return svg(
				'<path d="M10 12L6 8L10 4"' +
				' stroke="currentColor" stroke-width="2"' +
				' stroke-linecap="round" stroke-linejoin="round"/>',
				'sim-icon-chevron-left' + ( cls ? ' ' + cls : '' )
			);
		},

		/**
		 * Chevron right (carousel next button).
		 *
		 * @param {string} [cls] Extra CSS class.
		 * @returns {string}
		 */
		chevronRight: function ( cls ) {
			return svg(
				'<path d="M6 4L10 8L6 12"' +
				' stroke="currentColor" stroke-width="2"' +
				' stroke-linecap="round" stroke-linejoin="round"/>',
				'sim-icon-chevron-right' + ( cls ? ' ' + cls : '' )
			);
		},

		/**
		 * Image / media library icon (used in menu and modal header).
		 *
		 * @param {string} [cls]  Extra CSS class.
		 * @param {string} [size] Icon size in px. Defaults to "20".
		 * @returns {string}
		 */
		image: function ( cls, size ) {
			return svg(
				'<rect x="2" y="3" width="12" height="9" rx="2"' +
				' stroke="currentColor" stroke-width="1.5"' +
				' stroke-linecap="round" stroke-linejoin="round"/>' +
				'<circle cx="5.5" cy="6" r="1" fill="currentColor"/>' +
				'<path d="M2 9L5 7L7.5 8.5L10.5 5.5L14 8.5"' +
				' stroke="currentColor" stroke-width="1.5"' +
				' stroke-linecap="round" stroke-linejoin="round"/>',
				'sim-icon-image' + ( cls ? ' ' + cls : '' ),
				size || '16'
			);
		},

		/**
		 * Spinner / loading indicator (animated via CSS).
		 *
		 * @param {string} [cls] Extra CSS class.
		 * @returns {string}
		 */
		spinner: function ( cls ) {
			return svg(
				'<circle cx="8" cy="8" r="6"' +
				' stroke="currentColor" stroke-width="2"' +
				' stroke-linecap="round"' +
				' stroke-dasharray="28" stroke-dashoffset="10"/>',
				'sim-icon-spinner sim-icon-spin' + ( cls ? ' ' + cls : '' )
			);
		},

		/**
		 * Info circle (used in tips / notices).
		 *
		 * @param {string} [cls] Extra CSS class.
		 * @returns {string}
		 */
		info: function ( cls ) {
			return svg(
				'<circle cx="8" cy="8" r="6.5"' +
				' stroke="currentColor" stroke-width="1.5"/>' +
				'<path d="M8 7V11M8 5.5V5" stroke="currentColor"' +
				' stroke-width="1.5" stroke-linecap="round"/>',
				'sim-icon-info' + ( cls ? ' ' + cls : '' )
			);
		},

	};
} );
