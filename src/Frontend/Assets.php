<?php
/**
 * Front-end asset registration / enqueueing.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's scoped stylesheet and the Leaflet + markercluster map
 * stack, then enqueues on demand (only on pages that render our shortcodes), so
 * nothing loads site-wide. Map libraries come from a CDN to avoid bundling and
 * keep the keyless OSM path friction-free.
 */
final class Assets {

	private const LEAFLET_VERSION = '1.9.4';
	private const CLUSTER_VERSION = '1.5.3';

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_handles' ) );
	}

	/**
	 * Register (not enqueue) all handles up front.
	 */
	public function register_handles(): void {
		wp_register_style( 'lodestar', LODESTAR_URL . 'assets/css/lodestar.css', array(), LODESTAR_VERSION );

		$leaflet = 'https://unpkg.com/leaflet@' . self::LEAFLET_VERSION . '/dist/';
		$cluster = 'https://unpkg.com/leaflet.markercluster@' . self::CLUSTER_VERSION . '/dist/';

		wp_register_style( 'leaflet', $leaflet . 'leaflet.css', array(), self::LEAFLET_VERSION );
		wp_register_style( 'leaflet-markercluster', $cluster . 'MarkerCluster.css', array( 'leaflet' ), self::CLUSTER_VERSION );
		wp_register_style( 'leaflet-markercluster-default', $cluster . 'MarkerCluster.Default.css', array( 'leaflet-markercluster' ), self::CLUSTER_VERSION );

		wp_register_script( 'leaflet', $leaflet . 'leaflet.js', array(), self::LEAFLET_VERSION, true );
		wp_register_script( 'leaflet-markercluster', $cluster . 'leaflet.markercluster.js', array( 'leaflet' ), self::CLUSTER_VERSION, true );
		wp_register_script(
			'lodestar-map',
			LODESTAR_URL . 'assets/js/lodestar-map.js',
			array( 'leaflet', 'leaflet-markercluster' ),
			LODESTAR_VERSION,
			true
		);
	}

	/**
	 * Enqueue the base stylesheet.
	 */
	public static function enqueue_style(): void {
		if ( wp_style_is( 'lodestar', 'registered' ) ) {
			wp_enqueue_style( 'lodestar' );
		}
	}

	/**
	 * Enqueue the full map stack.
	 */
	public static function enqueue_map(): void {
		self::enqueue_style();
		foreach ( array( 'leaflet', 'leaflet-markercluster', 'leaflet-markercluster-default' ) as $style ) {
			if ( wp_style_is( $style, 'registered' ) ) {
				wp_enqueue_style( $style );
			}
		}
		if ( wp_script_is( 'lodestar-map', 'registered' ) ) {
			wp_enqueue_script( 'lodestar-map' );
		}
	}
}
