<?php
/**
 * Minimal WordPress function stubs for standalone (non-WP) unit tests.
 *
 * Every stub is guarded by function_exists so this file is a harmless no-op
 * under the real WP test suite.
 *
 * @package Lodestar
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}
if ( ! function_exists( 'esc_textarea' ) ) {
	function esc_textarea( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return (string) $url;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return (string) $text;
	}
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = 'default' ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}
if ( ! function_exists( 'checked' ) ) {
	function checked( $a, $b = true, $echo = true ) {
		$result = (string) $a === (string) $b || ( $a && $b ) ? ' checked' : '';
		if ( $echo ) {
			echo $result; // phpcs:ignore
		}
		return $result;
	}
}
if ( ! function_exists( 'selected' ) ) {
	function selected( $a, $b = true, $echo = true ) {
		$result = (string) $a === (string) $b ? ' selected' : '';
		if ( $echo ) {
			echo $result; // phpcs:ignore
		}
		return $result;
	}
}
