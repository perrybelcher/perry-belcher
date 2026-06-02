/**
 * Lodestar block editor registration.
 *
 * Plain JS (no build step) using the global `wp` runtime. Each block is dynamic
 * and server-rendered, so the editor previews real output via ServerSideRender
 * and `save` returns null. Attributes come from each block.json (registered
 * server-side); here we only supply edit/save.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var __ = ( wp.i18n && wp.i18n.__ ) || function ( s ) { return s; };
	var ServerSideRender = wp.serverSideRender;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var c = wp.components;

	/**
	 * Build an edit() that renders InspectorControls + a server preview.
	 *
	 * @param {string}   name     Block name.
	 * @param {Function} controls (props) => array of control elements.
	 */
	function editor( name, controls ) {
		return function ( props ) {
			var blockProps = useBlockProps ? useBlockProps() : {};

			var preview = ServerSideRender
				? el( ServerSideRender, { block: name, attributes: props.attributes } )
				: el( 'p', {}, __( 'Lodestar block', 'lodestar' ) );

			return el(
				Fragment,
				{},
				el( InspectorControls, {}, el( c.PanelBody, { title: __( 'Settings', 'lodestar' ) }, controls( props ) ) ),
				el( 'div', blockProps, preview )
			);
		};
	}

	function setAttr( props, key ) {
		return function ( value ) {
			var attrs = {};
			attrs[ key ] = value;
			props.setAttributes( attrs );
		};
	}

	function typeControl( props ) {
		return el( c.TextControl, {
			label: __( 'Directory type slug (blank = first)', 'lodestar' ),
			value: props.attributes.type || '',
			onChange: setAttr( props, 'type' )
		} );
	}

	var dynamic = { save: function () { return null; } };

	wp.blocks.registerBlockType( 'lodestar/search-form', Object.assign( {}, dynamic, {
		edit: editor( 'lodestar/search-form', function ( props ) {
			return [
				typeControl( props ),
				el( c.ToggleControl, {
					key: 'map',
					label: __( 'Show map', 'lodestar' ),
					checked: !! props.attributes.showMap,
					onChange: setAttr( props, 'showMap' )
				} )
			];
		} )
	} ) );

	wp.blocks.registerBlockType( 'lodestar/submit-form', Object.assign( {}, dynamic, {
		edit: editor( 'lodestar/submit-form', function ( props ) {
			return [ typeControl( props ) ];
		} )
	} ) );

	wp.blocks.registerBlockType( 'lodestar/listings-grid', Object.assign( {}, dynamic, {
		edit: editor( 'lodestar/listings-grid', function ( props ) {
			return [
				typeControl( props ),
				el( c.RangeControl, {
					key: 'per',
					label: __( 'Listings to show', 'lodestar' ),
					value: props.attributes.perPage || 12,
					min: 1,
					max: 48,
					onChange: setAttr( props, 'perPage' )
				} )
			];
		} )
	} ) );

	wp.blocks.registerBlockType( 'lodestar/single-listing', Object.assign( {}, dynamic, {
		edit: editor( 'lodestar/single-listing', function () { return []; } )
	} ) );

	wp.blocks.registerBlockType( 'lodestar/map', Object.assign( {}, dynamic, {
		edit: editor( 'lodestar/map', function ( props ) {
			return [
				typeControl( props ),
				el( c.RangeControl, {
					key: 'height',
					label: __( 'Map height (px)', 'lodestar' ),
					value: props.attributes.height || 420,
					min: 200,
					max: 800,
					onChange: setAttr( props, 'height' )
				} )
			];
		} )
	} ) );
} )( window.wp );
