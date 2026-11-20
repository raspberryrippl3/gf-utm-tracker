<?php
/**
 * Gravity Forms add-on that stores campaign tracking data with each entry.
 */

defined( 'ABSPATH' ) || exit;

class GFUT_Tracker extends GFAddOn {

	protected $_version                  = GFUT_VERSION;
	protected $_min_gravityforms_version = '2.5';
	protected $_slug                     = 'gf-utm-tracker';
	protected $_path                     = 'gf-utm-tracker/gf-utm-tracker.php';
	protected $_full_path                = GFUT_FILE;
	protected $_title                    = 'UTM Tracker for Gravity Forms';
	protected $_short_title              = 'UTM Tracker';

	/** Cookie that holds the captured values (JSON). */
	const COOKIE = 'gfut_data';

	/** Prefix for entry meta keys. */
	const META_PREFIX = 'gfut_';

	/** Longest value we keep for any one parameter. */
	const MAX_LENGTH = 500;

	private static $_instance = null;

	/** Values from the cookie, decoded once per request. */
	private $visitor_data = null;

	public static function get_instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/* ---------------------------------------------------------------------
	 * Tracked keys
	 * ------------------------------------------------------------------- */

	/**
	 * Query string parameters captured from the landing URL.
	 * Filter `gfut_tracked_params` to add your own (e.g. 'ref', 'affiliate').
	 *
	 * @return array key => label
	 */
	public function get_url_params() {
		$params = array(
			'utm_source'   => __( 'UTM Source', 'gf-utm-tracker' ),
			'utm_medium'   => __( 'UTM Medium', 'gf-utm-tracker' ),
			'utm_campaign' => __( 'UTM Campaign', 'gf-utm-tracker' ),
			'utm_term'     => __( 'UTM Term', 'gf-utm-tracker' ),
			'utm_content'  => __( 'UTM Content', 'gf-utm-tracker' ),
			'utm_id'       => __( 'UTM ID', 'gf-utm-tracker' ),
			'gclid'        => __( 'Google Click ID', 'gf-utm-tracker' ),
			'fbclid'       => __( 'Facebook Click ID', 'gf-utm-tracker' ),
			'msclkid'      => __( 'Microsoft Click ID', 'gf-utm-tracker' ),
		);

		$params = (array) apply_filters( 'gfut_tracked_params', $params );

		// Keys become cookie keys, meta keys and merge tags, so keep them simple.
		$clean = array();
		foreach ( $params as $key => $label ) {
			$key = strtolower( preg_replace( '/[^A-Za-z0-9_]/', '', (string) $key ) );
			if ( '' !== $key ) {
				$clean[ $key ] = $label;
			}
		}
		return $clean;
	}

	/**
	 * Everything stored: URL params plus landing page, referrer and first visit time.
	 *
	 * @return array key => label
	 */
	public function get_fields() {
		return array_merge(
			$this->get_url_params(),
			array(
				'landing_page' => __( 'Landing Page', 'gf-utm-tracker' ),
				'referrer'     => __( 'Referrer', 'gf-utm-tracker' ),
				'visit_time'   => __( 'Visit Time (UTC)', 'gf-utm-tracker' ),
			)
		);
	}

	/**
	 * Merge tag for a stored key. UTM keys keep their names ({utm_source});
	 * the rest get a utm_ prefix so they can't clash with core tags ({utm_referrer}).
	 */
	public function merge_tag_name( $key ) {
		return 0 === strpos( $key, 'utm_' ) ? $key : 'utm_' . $key;
	}

	/* ---------------------------------------------------------------------
	 * Boot
	 * ------------------------------------------------------------------- */

	public function init() {
		parent::init();

		// Capture on the front end.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_capture_script' ) );
		add_action( 'template_redirect', array( $this, 'capture_server_side' ), 1 );

		// Fill hidden fields whose "Parameter Name" matches a tracked key.
		add_filter( 'gform_field_value', array( $this, 'populate_field_value' ), 10, 3 );
		add_action( 'gform_pre_submission', array( $this, 'fill_hidden_fields_on_submit' ) );

		// Merge tags.
		add_filter( 'gform_custom_merge_tags', array( $this, 'add_merge_tags' ), 10, 4 );
		add_filter( 'gform_replace_merge_tags', array( $this, 'replace_merge_tags' ), 10, 7 );
	}

	public function init_admin() {
		parent::init_admin();
		add_filter( 'gform_entry_detail_meta_boxes', array( $this, 'add_entry_meta_box' ), 10, 3 );
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	public function plugin_settings_fields() {
		return array(
			array(
				'title'       => esc_html__( 'Tracking', 'gf-utm-tracker' ),
				'description' => '<p>' . esc_html__( 'A small script stores the campaign parameters from the URL a visitor lands on in a first-party cookie, so they are still known when that visitor fills in a form on a later page. Every form entry gets a copy.', 'gf-utm-tracker' ) . '</p>',
				'fields'      => array(
					array(
						'name'          => 'attribution',
						'label'         => esc_html__( 'Attribution', 'gf-utm-tracker' ),
						'type'          => 'radio',
						'default_value' => 'last',
						'choices'       => array(
							array(
								'label' => esc_html__( 'Last touch: a new visit with campaign parameters replaces what was stored', 'gf-utm-tracker' ),
								'value' => 'last',
							),
							array(
								'label' => esc_html__( 'First touch: keep the first campaign seen until the cookie expires', 'gf-utm-tracker' ),
								'value' => 'first',
							),
						),
					),
					array(
						'name'              => 'cookie_days',
						'label'             => esc_html__( 'Remember for (days)', 'gf-utm-tracker' ),
						'type'              => 'text',
						'input_type'        => 'number',
						'class'             => 'small',
						'default_value'     => '30',
						'validation_callback' => function ( $field, $value ) {
							if ( ! is_numeric( $value ) || (int) $value < 1 || (int) $value > 730 ) {
								$field->set_error( esc_html__( 'Enter a number of days between 1 and 730.', 'gf-utm-tracker' ) );
							}
						},
					),
				),
			),
			array(
				'title'       => esc_html__( 'How to use the data', 'gf-utm-tracker' ),
				'description' => $this->usage_help_html(),
				'fields'      => array(),
			),
		);
	}

	private function usage_help_html() {
		$tags = array();
		foreach ( array_keys( $this->get_fields() ) as $key ) {
			$tags[] = '<code>{' . esc_html( $this->merge_tag_name( $key ) ) . '}</code>';
		}

		return '<p>' . esc_html__( 'Values are saved automatically with every entry. They appear in a "Campaign Tracking" box on the entry screen, as columns in the entry list, and in entry exports.', 'gf-utm-tracker' ) . '</p>'
			. '<p>' . esc_html__( 'Merge tags for notifications and confirmations:', 'gf-utm-tracker' ) . ' ' . implode( ' ', $tags ) . ' <code>{utm_all}</code></p>'
			. '<p>' . esc_html__( 'Optional: to keep a value in a form field too, add a Hidden field, tick "Allow field to be populated dynamically" and set the Parameter Name to a key such as utm_source.', 'gf-utm-tracker' ) . '</p>';
	}

	public function get_attribution() {
		return 'first' === $this->get_plugin_setting( 'attribution' ) ? 'first' : 'last';
	}

	public function get_cookie_days() {
		$days = (int) $this->get_plugin_setting( 'cookie_days' );
		return $days > 0 ? min( $days, 730 ) : 30;
	}

	/**
	 * Whether to capture for this request. Filter `gfut_capture_enabled` to
	 * tie capture to a cookie consent plugin.
	 */
	public function capture_enabled() {
		return (bool) apply_filters( 'gfut_capture_enabled', true );
	}

	/* ---------------------------------------------------------------------
	 * Capture
	 * ------------------------------------------------------------------- */

	/**
	 * The browser script does the capture on every page, so it still works
	 * when pages are served from a cache.
	 */
	public function enqueue_capture_script() {
		if ( ! $this->capture_enabled() ) {
			return;
		}

		wp_enqueue_script( 'gfut-capture', GFUT_URL . 'assets/js/capture.js', array(), GFUT_VERSION, false );
		wp_add_inline_script(
			'gfut-capture',
			'window.gfutConfig = ' . wp_json_encode(
				array(
					'cookie'      => self::COOKIE,
					'days'        => $this->get_cookie_days(),
					'attribution' => $this->get_attribution(),
					'params'      => array_keys( $this->get_url_params() ),
					'maxLength'   => self::MAX_LENGTH,
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Server-side fallback for uncached page loads (or visitors without JS).
	 * Uses the same rules as the browser script.
	 */
	public function capture_server_side() {
		if ( is_admin() || wp_doing_ajax() || headers_sent() || ! $this->capture_enabled() ) {
			return;
		}

		$from_url = array();
		foreach ( array_keys( $this->get_url_params() ) as $key ) {
			if ( isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$value = $this->clean_value( wp_unslash( $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
				if ( '' !== $value ) {
					$from_url[ $key ] = $value;
				}
			}
		}

		$existing = $this->get_visitor_data();
		$has_data = ! empty( $existing );

		if ( $has_data && ( empty( $from_url ) || 'first' === $this->get_attribution() ) ) {
			return;
		}

		$scheme  = is_ssl() ? 'https://' : 'http://';
		$host    = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri     = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? wp_unslash( $_SERVER['HTTP_REFERER'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$data                 = $from_url;
		$data['landing_page'] = $this->clean_url( $scheme . $host . $uri );
		$data['referrer']     = $this->is_external_url( $referer ) ? $this->clean_url( $referer ) : '';
		$data['visit_time']   = gmdate( 'Y-m-d H:i:s' );

		$this->write_cookie( array_filter( $data, 'strlen' ) );
	}

	private function write_cookie( array $data ) {
		$expires = time() + DAY_IN_SECONDS * $this->get_cookie_days();
		$value   = rawurlencode( wp_json_encode( $data ) );

		setrawcookie(
			self::COOKIE,
			$value,
			array(
				'expires'  => $expires,
				'path'     => '/', // Same scope as the browser script's cookie.
				'secure'   => is_ssl(),
				'httponly' => false, // The browser script needs to read it.
				'samesite' => 'Lax',
			)
		);

		$_COOKIE[ self::COOKIE ] = wp_json_encode( $data );
		$this->visitor_data      = null;
	}

	private function is_external_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}
		$ref_host  = wp_parse_url( $url, PHP_URL_HOST );
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		return $ref_host && strtolower( $ref_host ) !== strtolower( (string) $site_host );
	}

	/* ---------------------------------------------------------------------
	 * Reading the stored values
	 * ------------------------------------------------------------------- */

	/**
	 * Values stored for the current visitor, whitelisted and sanitised.
	 *
	 * @return array key => value
	 */
	public function get_visitor_data() {
		if ( null !== $this->visitor_data ) {
			return $this->visitor_data;
		}

		$this->visitor_data = array();

		if ( empty( $_COOKIE[ self::COOKIE ] ) || ! is_string( $_COOKIE[ self::COOKIE ] ) ) {
			return $this->visitor_data;
		}

		$raw = json_decode( wp_unslash( $_COOKIE[ self::COOKIE ] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! is_array( $raw ) ) {
			return $this->visitor_data;
		}

		foreach ( array_keys( $this->get_fields() ) as $key ) {
			if ( isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ) {
				$value = in_array( $key, array( 'landing_page', 'referrer' ), true )
					? $this->clean_url( (string) $raw[ $key ] )
					: $this->clean_value( (string) $raw[ $key ] );
				if ( '' !== $value ) {
					$this->visitor_data[ $key ] = $value;
				}
			}
		}

		return $this->visitor_data;
	}

	public function get_visitor_value( $key ) {
		$data = $this->get_visitor_data();
		return isset( $data[ $key ] ) ? $data[ $key ] : '';
	}

	private function clean_value( $value ) {
		$value = sanitize_text_field( (string) $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, self::MAX_LENGTH ) : substr( $value, 0, self::MAX_LENGTH );
	}

	private function clean_url( $url ) {
		$url = esc_url_raw( trim( (string) $url ), array( 'http', 'https' ) );
		return substr( $url, 0, self::MAX_LENGTH * 2 );
	}

	/* ---------------------------------------------------------------------
	 * Entry meta (saved with every entry; list columns, filters, export)
	 * ------------------------------------------------------------------- */

	public function get_entry_meta( $entry_meta, $form_id ) {
		foreach ( $this->get_fields() as $key => $label ) {
			$entry_meta[ self::META_PREFIX . $key ] = array(
				'label'                      => $label,
				'is_numeric'                 => false,
				'is_default_column'          => false,
				'update_entry_meta_callback' => array( $this, 'entry_meta_value' ),
				'filter'                     => array(
					'operators' => array( 'is', 'isnot', 'contains' ),
				),
			);
		}
		return $entry_meta;
	}

	/**
	 * Called by Gravity Forms when it saves a new entry.
	 */
	public function entry_meta_value( $meta_key, $entry, $form ) {
		// Don't overwrite on later updates (e.g. an admin editing the entry).
		if ( ! empty( $entry[ $meta_key ] ) ) {
			return $entry[ $meta_key ];
		}

		$key   = substr( $meta_key, strlen( self::META_PREFIX ) );
		$value = $this->get_visitor_value( $key );

		/**
		 * Filter a tracked value before it is saved with an entry.
		 */
		return apply_filters( 'gfut_entry_value', $value, $key, $entry, $form );
	}

	/**
	 * Tracked values for an entry.
	 *
	 * @return array key => value (only non-empty)
	 */
	public function get_entry_data( $entry ) {
		$data = array();
		foreach ( array_keys( $this->get_fields() ) as $key ) {
			$value = rgar( $entry, self::META_PREFIX . $key );
			if ( '' === (string) $value && ! empty( $entry['id'] ) ) {
				$value = gform_get_meta( $entry['id'], self::META_PREFIX . $key );
			}
			if ( '' !== (string) $value ) {
				$data[ $key ] = (string) $value;
			}
		}
		return $data;
	}

	/* ---------------------------------------------------------------------
	 * Hidden fields (optional)
	 * ------------------------------------------------------------------- */

	public function populate_field_value( $value, $field, $name ) {
		if ( '' !== (string) $value || ! $name ) {
			return $value;
		}
		$fields = $this->get_fields();
		return isset( $fields[ $name ] ) ? $this->get_visitor_value( $name ) : $value;
	}

	/**
	 * Pages served from a cache render hidden fields without our values, so
	 * fill any that are still empty from the cookie when the form is posted.
	 */
	public function fill_hidden_fields_on_submit( $form ) {
		$keys = $this->get_fields();

		foreach ( $form['fields'] as $field ) {
			if ( empty( $field->allowsPrepopulate ) || empty( $field->inputName ) || ! empty( $field->inputs ) ) {
				continue;
			}
			if ( ! isset( $keys[ $field->inputName ] ) ) {
				continue;
			}
			$input = 'input_' . $field->id;
			if ( '' === rgpost( $input ) ) {
				$_POST[ $input ] = $this->get_visitor_value( $field->inputName );
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Merge tags
	 * ------------------------------------------------------------------- */

	public function add_merge_tags( $merge_tags, $form_id, $fields, $element_id ) {
		foreach ( $this->get_fields() as $key => $label ) {
			$merge_tags[] = array(
				'label' => $label,
				'tag'   => '{' . $this->merge_tag_name( $key ) . '}',
			);
		}
		$merge_tags[] = array(
			'label' => __( 'All Campaign Tracking', 'gf-utm-tracker' ),
			'tag'   => '{utm_all}',
		);
		return $merge_tags;
	}

	public function replace_merge_tags( $text, $form, $entry, $url_encode, $esc_html, $nl2br, $format ) {
		if ( ! is_string( $text ) || false === strpos( $text, '{utm_' ) ) {
			return $text;
		}

		$data = is_array( $entry ) && ! empty( $entry ) ? $this->get_entry_data( $entry ) : $this->get_visitor_data();

		if ( false !== strpos( $text, '{utm_all}' ) ) {
			$text = str_replace( '{utm_all}', $this->render_all( $data, $format ), $text );
		}

		foreach ( array_keys( $this->get_fields() ) as $key ) {
			$tag = '{' . $this->merge_tag_name( $key ) . '}';
			if ( false === strpos( $text, $tag ) ) {
				continue;
			}
			$value = isset( $data[ $key ] ) ? $data[ $key ] : '';
			if ( $url_encode ) {
				$value = rawurlencode( $value );
			}
			if ( $esc_html ) {
				$value = esc_html( $value );
			}
			$text = str_replace( $tag, $value, $text );
		}

		return $text;
	}

	private function render_all( array $data, $format ) {
		$labels = $this->get_fields();

		if ( empty( $data ) ) {
			return 'html' === $format ? '<p>' . esc_html__( 'No campaign tracking data.', 'gf-utm-tracker' ) . '</p>' : __( 'No campaign tracking data.', 'gf-utm-tracker' );
		}

		if ( 'html' !== $format ) {
			$lines = array();
			foreach ( $data as $key => $value ) {
				$lines[] = $labels[ $key ] . ': ' . $value;
			}
			return implode( "\n", $lines );
		}

		$rows = '';
		foreach ( $data as $key => $value ) {
			$rows .= '<tr><th style="text-align:left;padding:4px 12px 4px 0;vertical-align:top;">' . esc_html( $labels[ $key ] ) . '</th>'
				. '<td style="padding:4px 0;word-break:break-all;">' . esc_html( $value ) . '</td></tr>';
		}
		return '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;">' . $rows . '</table>';
	}

	/* ---------------------------------------------------------------------
	 * Entry detail screen
	 * ------------------------------------------------------------------- */

	public function add_entry_meta_box( $meta_boxes, $entry, $form ) {
		$meta_boxes['gfut_tracking'] = array(
			'title'    => esc_html__( 'Campaign Tracking', 'gf-utm-tracker' ),
			'callback' => array( $this, 'render_entry_meta_box' ),
			'context'  => 'side',
		);
		return $meta_boxes;
	}

	public function render_entry_meta_box( $args ) {
		$data   = $this->get_entry_data( rgar( $args, 'entry', array() ) );
		$labels = $this->get_fields();

		if ( empty( $data ) ) {
			echo '<p>' . esc_html__( 'No campaign tracking data was captured for this entry.', 'gf-utm-tracker' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="border:0;box-shadow:none;"><tbody>';
		foreach ( $data as $key => $value ) {
			$display = in_array( $key, array( 'landing_page', 'referrer' ), true )
				? '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $value ) . '</a>'
				: esc_html( $value );
			printf(
				'<tr><th style="width:40%%;font-weight:600;">%s</th><td style="word-break:break-all;">%s</td></tr>',
				esc_html( $labels[ $key ] ),
				$display // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
			);
		}
		echo '</tbody></table>';
	}
}
