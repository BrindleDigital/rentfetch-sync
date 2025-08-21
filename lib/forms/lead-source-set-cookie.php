<?php
/**
 * Handle setting the wordpress_rentfetch_lead_source cookie when a ?lead_source=... parameter is present.
 *
 * This file is intentionally small and is safe to load on every request. It will not output
 * anything to the browser; it only sets/updates a cookie and updates $_COOKIE for the
 * current request so other parts of the plugin can read it immediately.
 *
 * Cookie specifics:
 * - Name: wordpress_rentfetch_lead_source
 * - Lifetime: 30 days
 * - Path: /
 * - SameSite: Lax (to allow general navigation while mitigating CSRF risk)
 * - Secure: set when using HTTPS
 * - HttpOnly: false (so JavaScript can read it)
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Set/update the wordpress_rentfetch_lead_source cookie when a ?lead_source query param is present.
 *
 * This function is intentionally small and safe to call on every request. It will set a
 * sitewide cookie for 30 days and update $_COOKIE for the current request so other
 * plugin code can read it immediately.
 */
function rfs_set_lead_source_cookie() {
	if ( ! isset( $_GET['lead_source'] ) ) {
		return;
	}

	// Sanitize the incoming value
	$value = sanitize_text_field( wp_unslash( $_GET['lead_source'] ) );
	if ( '' === $value ) {
		return;
	}

	// Use the new cookie name to avoid collisions with other plugins/sites.
	$cookie_name = 'wordpress_rentfetch_lead_source';
	$cookie_lifetime_seconds = 30 * 24 * 60 * 60; // 30 days
	$expires = time() + $cookie_lifetime_seconds;

	// Encode to match JS behavior (rawurlencode on write, rawurldecode on read)
	$encoded_value = rawurlencode( $value );

	// Build cookie params. PHP's setcookie supports an options array since 7.3.
	$cookie_options = array(
		'expires'  => $expires,
		'path'     => '/',
		'domain'   => '',
		'secure'   => is_ssl(),
		'httponly' => false, // JS should be able to read it
		'samesite' => 'Lax',
	);

	// Use setcookie with options when available
	if ( PHP_VERSION_ID >= 70300 ) {
		setcookie( $cookie_name, $encoded_value, $cookie_options );
	} else {
		// Fallback for older PHP: build header manually for best parity
		$cookie_header = rawurlencode( $cookie_name ) . '=' . $encoded_value . '; Expires=' . gmdate( 'D, d-M-Y H:i:s T', $expires ) . '; Path=/';
		if ( is_ssl() ) {
			$cookie_header .= '; Secure';
		}
		$cookie_header .= '; SameSite=Lax';
		header( 'Set-Cookie: ' . $cookie_header, false );
	}

	// Make the value available to the rest of this request
	$_COOKIE[ $cookie_name ] = $encoded_value;
}

add_action( 'init', 'rfs_set_lead_source_cookie', 1 );
