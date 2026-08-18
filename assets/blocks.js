/**
 * Moksa Points — editor registration for the plugin's dynamic blocks.
 *
 * Every block renders server-side (each one delegates to the shortcode that already exists), so the
 * editor only needs a placeholder: a title and one line saying what will appear on the front end.
 * That keeps this file plain ES5 with no build step — what ships is what runs.
 *
 * Labels come from PHP (window.moksafopoiBlocks) so they use the plugin's own translations.
 */
( function ( blocks, element, blockEditor ) {
	'use strict';

	if ( ! blocks || ! element ) {
		return;
	}

	var createElement = element.createElement;
	var useBlockProps = blockEditor && blockEditor.useBlockProps ? blockEditor.useBlockProps : null;
	var labels = window.moksafopoiBlocks || {};

	Object.keys( labels ).forEach( function ( name ) {
		var spec = labels[ name ] || {};

		blocks.registerBlockType( 'moksafopoi/' + name, {
			apiVersion: 2,
			title: spec.title || name,
			description: spec.description || '',
			category: 'widgets',
			icon: 'star-filled',
			supports: { html: false },

			edit: function () {
				var props = useBlockProps ? useBlockProps( { className: 'moksafopoi-block-placeholder' } ) : {};

				return createElement(
					'div',
					props,
					createElement( 'strong', null, spec.title || name ),
					createElement( 'div', { style: { opacity: 0.7 } }, spec.description || '' )
				);
			},

			// Dynamic block: the front end is rendered by PHP.
			save: function () {
				return null;
			},
		} );
	} );
} )( window.wp && window.wp.blocks, window.wp && window.wp.element, window.wp && window.wp.blockEditor );
