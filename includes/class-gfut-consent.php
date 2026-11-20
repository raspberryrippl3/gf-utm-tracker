<?php
/**
 * Cookie consent: only store tracking data once the visitor has accepted the
 * right cookie category.
 *
 * Supported, in this order:
 *  1. The TWD Cookie Banner Plugin (CBP_* classes, `cbp_consent` cookie,
 *     `cbp:consent` browser event). The cookie is also registered with that
 *     plugin so it is listed in its cookie table and deleted on withdrawal.
 *  2. The WP Consent API (Complianz, CookieYes, Cookiebot and others).
 *  3. Neither installed: capture without asking.
 */

defined( 'ABSPATH' ) || exit;

class GFUT_Consent {

	/** Which consent system is active: 'cbp', 'wpconsent' or 'none'. */
	public static function provider() {
		if ( class_exists( 'CBP_Server_Cookies' ) && class_exists( 'CBP_Categories' ) ) {
			$provider = 'cbp';
		} elseif ( function_exists( 'wp_has_consent' ) ) {
			$provider = 'wpconsent';
		} else {
			$provider = 'none';
		}
		return (string) apply_filters( 'gfut_consent_provider', $provider );
	}

	/**
	 * Category the tracking cookie belongs to, in the active provider's terms.
	 * Analytics/statistics by default, matching how the cookie banner classes
	 * other campaign attribution cookies (AFL UTM, WooCommerce sbjs_*).
	 */
	public static function category( $provider = null ) {
		$provider = $provider ? $provider : self::provider();
		$category = 'wpconsent' === $provider ? 'statistics' : 'analytics';
		return (string) apply_filters( 'gfut_consent_category', $category, $provider );
	}

	public static function init() {
		// Register the cookie with the cookie banner plugin.
		add_filter( 'cbp_cleanup_cookies', array( __CLASS__, 'cbp_cleanup_cookies' ) );
		add_filter( 'cbp_local_cookie_db', array( __CLASS__, 'cbp_cookie_db' ) );

		// Declare the plugin to the WP Consent API.
		$plugin = plugin_basename( GFUT_FILE );
		add_filter( "wp_consent_api_registered_{$plugin}", '__return_true' );
	}

	/**
	 * Has the visitor consented, judged from the request's cookies?
	 * Used for the server-side capture fallback.
	 */
	public static function has_consent() {
		$provider = self::provider();

		if ( 'cbp' === $provider ) {
			// The banner's scanner must see the cookie to list it.
			if ( class_exists( 'CBP_Scanner' ) && CBP_Scanner::is_scan_request() ) {
				return true;
			}
			return CBP_Server_Cookies::allowed( self::category( $provider ) );
		}

		if ( 'wpconsent' === $provider ) {
			return (bool) wp_has_consent( self::category( $provider ) );
		}

		return true;
	}

	/** Settings for the browser script. */
	public static function script_config() {
		$provider = self::provider();
		return array(
			'provider' => $provider,
			'category' => self::category( $provider ),
		);
	}

	public static function cbp_cleanup_cookies( $cookies ) {
		$cat = self::category( 'cbp' );
		if ( ! isset( $cookies[ $cat ] ) || ! is_array( $cookies[ $cat ] ) ) {
			$cookies[ $cat ] = array();
		}
		$cookies[ $cat ][] = GFUT_Tracker::COOKIE;
		return $cookies;
	}

	public static function cbp_cookie_db( $rows ) {
		$rows[] = array(
			GFUT_Tracker::COOKIE,
			self::category( 'cbp' ),
			'UTM Tracker for Gravity Forms',
			'Records the campaign (UTM parameters and ad click IDs), landing page and referrer of the visit so enquiries made through website forms can be attributed to marketing campaigns.',
		);
		return $rows;
	}
}
