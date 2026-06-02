/**
 * Lodestar map initialiser — Leaflet + markercluster.
 *
 * Plain ES5-ish JS (no build step). Hydrates every `[data-lodestar-map]`
 * element from its `data-markers` JSON, clustering markers so the map stays
 * responsive at scale. OSM tiles by default (keyless).
 */
( function () {
	'use strict';

	function initMap( el ) {
		if ( typeof window.L === 'undefined' || el.dataset.lodestarReady ) {
			return;
		}

		var markers;
		try {
			markers = JSON.parse( el.getAttribute( 'data-markers' ) || '[]' );
		} catch ( e ) {
			markers = [];
		}

		var height = parseInt( el.getAttribute( 'data-height' ), 10 );
		if ( height > 0 ) {
			el.style.height = height + 'px';
		}

		var map = window.L.map( el );

		window.L.tileLayer( 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			maxZoom: 19,
			attribution: '&copy; OpenStreetMap contributors'
		} ).addTo( map );

		var useCluster = typeof window.L.markerClusterGroup === 'function';
		var group = useCluster ? window.L.markerClusterGroup() : window.L.layerGroup();
		var bounds = [];

		markers.forEach( function ( m ) {
			if ( typeof m.lat !== 'number' || typeof m.lng !== 'number' ) {
				return;
			}
			var marker = window.L.marker( [ m.lat, m.lng ] );
			if ( m.title ) {
				var html = m.url
					? '<a href="' + encodeURI( m.url ) + '">' + escapeHtml( m.title ) + '</a>'
					: escapeHtml( m.title );
				marker.bindPopup( html );
			}
			group.addLayer( marker );
			bounds.push( [ m.lat, m.lng ] );
		} );

		map.addLayer( group );

		if ( bounds.length ) {
			map.fitBounds( bounds, { padding: [ 30, 30 ], maxZoom: 15 } );
		} else {
			map.setView( [ 0, 0 ], 2 );
		}

		el.dataset.lodestarReady = '1';
	}

	function escapeHtml( str ) {
		return String( str ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function initAll() {
		var maps = document.querySelectorAll( '[data-lodestar-map]' );
		Array.prototype.forEach.call( maps, initMap );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initAll );
	} else {
		initAll();
	}
} )();
