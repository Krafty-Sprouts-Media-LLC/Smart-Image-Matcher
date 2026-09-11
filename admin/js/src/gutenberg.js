/**
 * gutenberg.js — Gutenberg sidebar + client-side Abilities.
 *
 * Registers:
 *   - A PluginSidebar (header pin) that opens the matcher modal.
 *   - Client-side Ability: "smart-image-matcher/find-images-for-current-post"
 *   - Client-side Ability: "smart-image-matcher/insert-best-match-for-selected-heading"
 *
 * Uses wp.element.createElement — no JSX, no build step required.
 * Uses SimIcons for all iconography.
 *
 * @package SmartImageMatcher
 * @since   3.0.0
 */

( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.plugins ) {
		return; // Not on a block editor screen.
	}

	const { registerPlugin }            = wp.plugins;
	const { PluginSidebar }             = wp.editor;
	const { PanelBody, Button }         = wp.components;
	const { Fragment, createElement }                    = wp.element;
	const { select }                                     = wp.data;
	const { __  }                                        = wp.i18n;
	const Icons                                          = window.SimIcons || {};

	// -------------------------------------------------------------------------
	// Custom SVG icon (matches the menu icon in PHP)
	// -------------------------------------------------------------------------

	const SimIcon = ( props ) => {
		const { className, ...rest } = props || {};
		const iconClass = 'sim-gutenberg-plugin-icon' + ( className ? ' ' + className : '' );

		return createElement( 'svg', {
			...rest,
			className: iconClass,
			width: 24,
			height: 24,
			viewBox: '0 0 20 20',
			fill: 'none',
			xmlns: 'http://www.w3.org/2000/svg',
			'aria-hidden': true,
			focusable: 'false',
		}, [
			createElement( 'path', {
				key: 'frame',
				d: 'M2 3C2 2.44772 2.44772 2 3 2H17C17.5523 2 18 2.44772 18 3V13C18 13.5523 17.5523 14 17 14H3C2.44772 14 2 13.5523 2 13V3Z',
				stroke: 'currentColor',
				strokeWidth: 1.5,
				strokeLinecap: 'round',
				strokeLinejoin: 'round',
			} ),
			createElement( 'circle', { key: 'dot', cx: 7, cy: 7, r: 1.5, fill: 'currentColor' } ),
			createElement( 'path', {
				key: 'wave',
				d: 'M2 11L5.5 8L9 10.5L13.5 6L18 10',
				stroke: 'currentColor',
				strokeWidth: 1.5,
				strokeLinecap: 'round',
				strokeLinejoin: 'round',
			} ),
		] );
	};

	// -------------------------------------------------------------------------
	// Helper: open the modal
	// -------------------------------------------------------------------------

	function openSimModal() {
		if ( typeof window.simOpenModal === 'function' ) {
			window.simOpenModal();
		}
	}

	function matcherData() {
		return window.smartImageMatcherData || {};
	}

	function restNonce() {
		const data = matcherData();
		return data.nonces && data.nonces.wpRest ? data.nonces.wpRest : '';
	}

	function matchMode() {
		const features = matcherData().features || {};
		return features.aiMatching ? 'ai' : 'keyword';
	}

	async function restJson( url, options ) {
		const resp = await fetch( url, options );
		const data = await resp.json().catch( () => ( {} ) );
		if ( ! resp.ok ) {
			let message = typeof data.message === 'string' ? data.message : '';
			message = message.replace( /<[^>]+>/g, ' ' ).replace( /\s+/g, ' ' ).trim();
			if ( /critical error on this website/i.test( message ) ) {
				message = 'Request failed with a server error. Open Smart Image Matcher → Dashboard for the last error.';
			}
			throw new Error( message || ( 'HTTP ' + resp.status ) );
		}
		return data;
	}

	async function pollMatchStatus( pollUrl ) {
		const maxAttempts = 90;
		for ( let attempt = 0; attempt < maxAttempts; attempt++ ) {
			await new Promise( ( resolve ) => {
				window.setTimeout( resolve, 2000 );
			} );
			try {
				const data = await restJson( pollUrl, {
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': restNonce() },
				} );
				if ( data.done ) {
					return data;
				}
			} catch ( err ) {
				// Keep polling through transient network errors.
			}
		}
		throw new Error( __( 'AI matching timed out. Try again.', 'smart-image-matcher' ) );
	}

	async function requestMatches( postId ) {
		const data = await restJson(
			`/wp-json/smart-image-matcher/v1/posts/${ postId }/match`,
			{
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': restNonce(),
				},
				body: JSON.stringify( { post_id: postId, mode: matchMode() } ),
			}
		);

		if ( data.status === 'queued' && data.poll_url ) {
			return pollMatchStatus( data.poll_url );
		}

		return data;
	}

	// -------------------------------------------------------------------------
	// Header pin / complementary sidebar (opens the matcher modal)
	// -------------------------------------------------------------------------

	const SimSidebarMenuItem = () =>
		// Only render when PluginSidebar is available (WP 6.0+).
		wp.editor.PluginSidebarMoreMenuItem
			? createElement( wp.editor.PluginSidebarMoreMenuItem, {
				target: 'sim-sidebar',
				icon:   createElement( SimIcon ),
			}, __( 'Smart Image Matcher', 'smart-image-matcher' ) )
			: null;

	const SimSidebar = () =>
		createElement( PluginSidebar, {
			name:  'sim-sidebar',
			title: __( 'Smart Image Matcher', 'smart-image-matcher' ),
			icon:  createElement( SimIcon ),
		},
			createElement( PanelBody, { title: __( 'Smart Image Matcher', 'smart-image-matcher' ), initialOpen: true }, [
				createElement( 'p', {
					key:   'desc',
					style: { marginBottom: '16px', color: '#757575', fontSize: '13px', lineHeight: '1.6' },
				}, __( 'Match your post headings to relevant images from the media library.', 'smart-image-matcher' ) ),

				createElement( Button, {
					key:       'open-btn',
					isPrimary: true,
					style:     { width: '100%' },
					onClick:   openSimModal,
				}, __( 'Open Smart Image Matcher', 'smart-image-matcher' ) ),
			] )
		);

	// -------------------------------------------------------------------------
	// Root plugin component
	// -------------------------------------------------------------------------

	const SimPlugin = () =>
		createElement( Fragment, null, [
			createElement( SimSidebar, { key: 'sim-sidebar' } ),
			SimSidebarMenuItem ? createElement( SimSidebarMenuItem, { key: 'sim-sidebar-menu' } ) : null,
		] );

	registerPlugin( 'smart-image-matcher', {
		render: SimPlugin,
		icon:   createElement( SimIcon ),
	} );

	// -------------------------------------------------------------------------
	// Client-side Abilities (@wordpress/abilities, WP 7.0+)
	// -------------------------------------------------------------------------

	if ( wp.abilities && wp.abilities.registerAbility ) {
		const { registerAbility } = wp.abilities;

		// Ability 1: "Find images for current post"
		registerAbility( 'smart-image-matcher/find-images-for-current-post', {
			label:       __( 'Find images for current post', 'smart-image-matcher' ),
			description: __( 'Scan headings in this post and suggest matching media-library images.', 'smart-image-matcher' ),
			category:    'media',
			execute: function () {
				openSimModal();
			},
		} );

		// Ability 2: "Insert best match for selected heading"
		registerAbility( 'smart-image-matcher/insert-best-match-for-selected-heading', {
			label:       __( 'Insert best match for selected heading', 'smart-image-matcher' ),
			description: __( 'Run matching for the selected heading block and insert the top result.', 'smart-image-matcher' ),
			category:    'media',
			isEligible: function () {
				const block = select( 'core/block-editor' ).getSelectedBlock();
				return !! ( block && block.name === 'core/heading' );
			},
			execute: async function () {
				const block  = select( 'core/block-editor' ).getSelectedBlock();
				const postId = select( 'core/editor' ).getCurrentPostId();

				if ( ! block || ! postId ) return;

				const headingText = block.attributes && block.attributes.content
					? block.attributes.content.replace( /<[^>]+>/g, '' )
					: '';

				if ( ! headingText ) return;

				try {
					if ( matchMode() === 'ai' ) {
						wp.data.dispatch( 'core/notices' ).createInfoNotice(
							__( 'AI matching in progress…', 'smart-image-matcher' ),
							{ id: 'sim-ai-matching', isDismissible: true }
						);
					}

					const data = await requestMatches( postId );
					wp.data.dispatch( 'core/notices' ).removeNotice( 'sim-ai-matching' );

					const groups  = data.matches || [];
					const group   = groups.find( g => g.heading && g.heading.text && g.heading.text.trim() === headingText.trim() );
					const topMatch = group && group.matches && group.matches[0];

					if ( ! topMatch ) {
						wp.data.dispatch( 'core/notices' ).createWarningNotice(
							__( 'No matching image found for the selected heading.', 'smart-image-matcher' ),
							{ id: 'sim-no-match', isDismissible: true }
						);
						return;
					}

					await restJson(
						`/wp-json/smart-image-matcher/v1/posts/${ postId }/insert`,
						{
							method: 'POST',
							credentials: 'same-origin',
							headers: {
								'Content-Type': 'application/json',
								'X-WP-Nonce': restNonce(),
							},
							body: JSON.stringify( {
								post_id:       postId,
								heading_hash:  group.heading.heading_hash,
								image_id:      topMatch.image_id,
							} ),
						}
					);

					wp.data.dispatch( 'core/notices' ).createSuccessNotice(
						__( 'Image inserted successfully.', 'smart-image-matcher' ),
						{ id: 'sim-insert-success', isDismissible: true }
					);

				} catch ( err ) {
					wp.data.dispatch( 'core/notices' ).removeNotice( 'sim-ai-matching' );
					wp.data.dispatch( 'core/notices' ).createErrorNotice(
						err && err.message
							? err.message
							: __( 'Smart Image Matcher: failed to insert image.', 'smart-image-matcher' ),
						{ id: 'sim-insert-error', isDismissible: true }
					);
				}
			},
		} );
	}

} )( window.wp );
