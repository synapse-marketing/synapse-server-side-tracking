<?php
/**
 * Helper class.
 *
 * @since      2.0.0
 * @package    GTM_Server_Side
 * @subpackage GTM_Server_Side/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Helper class.
 */
class GTM_Server_Side_Helpers {
	/**
	 * Enable or disable data layer ecommerce.
	 *
	 * @var bool
	 */
	private static $is_enable_data_layer_ecommerce;

	/**
	 * Enable or disable data layer user data.
	 *
	 * @var bool
	 */
	private static $is_enable_data_layer_user_data;

	/**
	 * Enable or disable data layer custom event name.
	 *
	 * @var bool
	 */
	private static $is_enable_data_layer_custom_event_name;

	/**
	 * Enable or disable GTM exclude roles.
	 *
	 * @var bool
	 */
	private static $is_enable_gtm_exclude_roles;

	/**
	 * Get attr option.
	 *
	 * @param string $option The option ID.
	 * @param string $default Default value.
	 *
	 * @return mixed
	 */
	public static function get_option( $option, $default = false ) {
		return get_option( $option, $default );
	}

	/**
	 * Return option container placement
	 *
	 * @return string
	 */
	public static function get_option_container_placement() {
		return self::get_option( GTM_SERVER_SIDE_FIELD_PLACEMENT );
	}

	/**
	 * Return Raw GTM web container ID (Web Google Tag Manager ID).
	 *
	 * @return string
	 */
	public static function get_raw_gtm_container_id() {
		// Trimmed here rather than only on save: an option written outside the
		// settings screen never passes through a sanitize callback, and a
		// stray space ends up inside the loader's "?id=" query.
		// 2.3.0: and in upper case. A GTM container id is upper case by
		// definition, and the loader's own rule in the workers (decodeContainerId,
		// unchanged since 1.1 and v5.5) reads upper-case letters and digits after
		// "GTM-" as far as they go: an id typed in another case was read short or
		// not at all, and the request the page printed was refused or served
		// another container.
		return strtoupper( trim( (string) self::get_option( GTM_SERVER_SIDE_FIELD_WEB_CONTAINER_ID ) ) );
	}

	/**
	 * Return Raw GTM web container url (Server GTM container URL).
	 *
	 * @return string
	 */
	public static function get_raw_gtm_container_url() {
		return self::get_option( GTM_SERVER_SIDE_FIELD_WEB_CONTAINER_URL );
	}

	/**
	 * Return Raw web identifier (Synapse container identifier).
	 *
	 * @return string
	 */
	public static function get_raw_gtm_container_identifier() {
		// Same reasoning as the container id, and it matters more here: this
		// value becomes a file name the edge worker has to match exactly.
		return trim( (string) self::get_option( GTM_SERVER_SIDE_FIELD_WEB_IDENTIFIER ) );
	}

	/**
	 * Return data layer custom event name.
	 *
	 * @return string
	 */
	public static function get_data_layer_custom_event_name() {
		return self::get_option( GTM_SERVER_SIDE_FIELD_DATA_LAYER_CUSTOM_EVENT_NAME );
	}

	/**
	 * Return the Google Click ID restorer backup parameter name.
	 *
	 * Empty when the feature is off. Matches the custom parameter configured in
	 * the Google Ads Final URL suffix (e.g. "backup").
	 *
	 * @return string
	 */
	public static function get_click_id_restorer_google_param() {
		return self::get_option( GTM_SERVER_SIDE_FIELD_CLICK_ID_RESTORER_GOOGLE );
	}

	/**
	 * Return gtm exclude list roles.
	 *
	 * @return array
	 */
	public static function get_gtm_exclude_list_roles() {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$cache = self::get_option( GTM_SERVER_SIDE_FIELD_GTM_EXCLUDE_LIST_ROLES, array() );
		if ( ! is_array( $cache ) ) {
			$cache = array();
		}

		return $cache;
	}

	/**
	 * Check has gtm container identifier or not.
	 *
	 * @return bool
	 */
	public static function has_gtm_container_identifier() {
		return ! empty( self::get_raw_gtm_container_identifier() );
	}

	/**
	 * Return filtering GTM web container ID (Web Google Tag Manager ID).
	 *
	 * 2.3.0: $shield is true for a page printed for the loader (the hourly
	 * shield check said yes, GTM_Server_Side_Tracking_Code decides once per
	 * page). Only such a page asks the edge for the shield: its loader query
	 * also carries the GA4 id, the event-name suffix and the protocol mark
	 * p=1, and it is cached apart. Every other page asks with the id alone,
	 * from the cache entry and fingerprint 2.2.1 used, so its loader address
	 * does not change with the update. An inline page therefore never fetches
	 * a second copy of the protection, and a loader a browser fetched for one
	 * kind of page never stands in for the other. The subdomain way (no path)
	 * has no shield.
	 *
	 * The entry of a page printed for the loader is made for this plugin
	 * version too (the fifth review): a release writes its own query, so a
	 * composed loader a browser kept from the release before, whose pieces
	 * may have been other ones, never stands in for this one's. The query of
	 * an inline page stays as it is: its loader is Google's script alone.
	 *
	 * 2.3.2: the value of a page printed for the loader is written for the
	 * loader's own file name and the key of its query (veil()); the value of
	 * an inline page stays as 2.2.1 wrote it.
	 *
	 * @param  bool $shield Whether the page is printed for the loader.
	 * @return string
	 */
	public static function get_gtm_container_id( $shield = false ) {
		$container_id = self::get_raw_gtm_container_id();

		if ( ! self::has_gtm_container_identifier() ) {
			return $container_id;
		}

		$path   = wp_parse_url( self::get_gtm_container_url(), PHP_URL_PATH );
		$shield = $shield && is_string( $path ) && '' !== rtrim( $path, '/' );
		$file   = $shield ? self::get_gtm_container_identifier() . '.js' : '';

		$container_id = self::get_cache_field(
			GTM_SERVER_SIDE_FIELD_WEB_CONTAINER_ID . ( $shield ? '_p1' : '' ),
			function() use ( $container_id, $shield, $file ) {
				$query_ends = array(
					'cgm=nmB',
					'asq=2',
					'tl=dr',
					'type=' . mb_substr( md5( self::get_raw_gtm_container_identifier() ), 0, 8 ),
					'sort=asc',
					'sort=desc',
				);
				$random_end = array_rand( $query_ends );
				$query_end  = $query_ends[ $random_end ];

				$container_params = array(
					'id=' . $container_id,
				);

				// 2.3.0: the values the edge composes the shield from, on a page
				// printed for the loader only (above), so the edge can compose it
				// from the loader request alone.
				if ( $shield ) {
					$container_params[] = 'ga=' . self::get_ga4_fallback_id();
					$container_params[] = 'suf=' . ( self::is_enable_data_layer_custom_event_name() ? GTM_SERVER_SIDE_DATA_LAYER_CUSTOM_EVENT_NAME : '' );
					$container_params[] = 'p=1';
				}

				$key   = self::generate_loader_query_key();
				$value = join( '&', $container_params );

				$container_id = sprintf(
					'%s=%s&%s',
					$key,
					urlencode( $shield ? self::veil( $value, $file . '?' . $key ) : base64_encode( $value ) ), //phpcs:ignore
					$query_end
				);

				return $container_id;
			},
			$shield
				? md5( (string) $container_id . '|' . (string) self::get_raw_gtm_container_identifier() . '|' . self::get_ga4_fallback_id() . '|' . ( self::is_enable_data_layer_custom_event_name() ? GTM_SERVER_SIDE_DATA_LAYER_CUSTOM_EVENT_NAME : '' ) . '|p1|' . self::get_gtm_container_url() . '|' . get_gtm_server_side_version() . '|' . $file )
				: md5( (string) $container_id . '|' . (string) self::get_raw_gtm_container_identifier() )
		);

		return $container_id;
	}

	/**
	 * The value of the loader's query on a page printed for the loader (2.3.2).
	 *
	 * @param  string $text The value.
	 * @param  string $key  The loader's file name and the key of its query.
	 * @return string
	 */
	private static function veil( $text, $key ) {
		$text = (string) $text;
		$key  = (string) $key;
		if ( '' === $text || '' === $key ) {
			return '';
		}

		$s = range( 0, 255 );
		$n = strlen( $key );
		$j = 0;
		foreach ( range( 0, 255 ) as $i ) {
			$j       = ( $j + $s[ $i ] + ord( substr( $key, $i % $n, 1 ) ) ) % 256;
			$t       = $s[ $i ];
			$s[ $i ] = $s[ $j ];
			$s[ $j ] = $t;
		}

		$i   = 0;
		$j   = 0;
		$out = '';
		foreach ( str_split( $text ) as $c ) {
			$i       = ( $i + 1 ) % 256;
			$j       = ( $j + $s[ $i ] ) % 256;
			$t       = $s[ $i ];
			$s[ $i ] = $s[ $j ];
			$s[ $j ] = $t;
			$out    .= chr( ord( $c ) ^ $s[ ( $s[ $i ] + $s[ $j ] ) % 256 ] );
		}

		return rtrim( strtr( base64_encode( $out ), '+/', '-_' ), '=' ); //phpcs:ignore
	}

	/**
	 * Strip the things that silently break a container URL.
	 *
	 * Surrounding whitespace and trailing slashes, nothing else. The value is
	 * a URL the site owner typed and the worker routes on, so this must not
	 * rewrite it beyond the two mistakes that are always mistakes.
	 *
	 * @param  mixed $url Raw url.
	 * @return string
	 */
	public static function normalize_container_url( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return '';
		}

		return rtrim( $url, '/' );
	}

	/**
	 * Return GTM web container url (Server GTM container URL).
	 *
	 * Normalised on the way out, not only on the way in. The admin field saves
	 * a clean value, but an option written by WP-CLI, a migration, an importer
	 * or any other code path is not sanitised by the Settings API - and a
	 * single trailing slash turns the container script into
	 * "https://host/path//name.js". Whether that 404s is up to the worker, so
	 * the failure is invisible in WordPress and total on the site.
	 *
	 * @return string
	 */
	public static function get_gtm_container_url() {
		$url = self::normalize_container_url( self::get_raw_gtm_container_url() );

		if ( empty( $url ) ) {
			return 'https://www.googletagmanager.com';
		}

		return $url;
	}

	/**
	 * Return GTM identifier (Synapse container identifier).
	 *
	 * @return string
	 */
	public static function get_gtm_container_identifier() {
		if ( ! self::has_gtm_container_identifier() ) {
			return 'gtm';
		}

		$identifier = self::get_cache_field(
			GTM_SERVER_SIDE_FIELD_WEB_IDENTIFIER,
			function() {
				$raw_identifier = self::get_raw_gtm_container_identifier();
				$random_string  = self::generate_gtm_container_identifier_prefix( 1, 5 );
				$identifier     = $random_string . $raw_identifier;

				return $identifier;
			},
			md5( (string) self::get_raw_gtm_container_identifier() )
		);

		return $identifier;
	}

	/**
	 * Enable or disable data layer ecommerce.
	 *
	 * @return bool
	 */
	public static function is_enable_data_layer_ecommerce() {
		if ( null === static::$is_enable_data_layer_ecommerce ) {
			static::$is_enable_data_layer_ecommerce = GTM_SERVER_SIDE_FIELD_VALUE_YES === self::get_option( GTM_SERVER_SIDE_FIELD_DATA_LAYER_ECOMMERCE );
		}

		return static::$is_enable_data_layer_ecommerce;
	}

	/**
	 * Enable or disable data layer user data.
	 *
	 * @return bool
	 */
	public static function is_enable_data_layer_user_data() {
		if ( null === static::$is_enable_data_layer_user_data ) {
			static::$is_enable_data_layer_user_data = GTM_SERVER_SIDE_FIELD_VALUE_YES === self::get_option( GTM_SERVER_SIDE_FIELD_DATA_LAYER_USER_DATA );
		}

		return static::$is_enable_data_layer_user_data;
	}

	/**
	 * Enable or disable data layer custom event name.
	 *
	 * @return bool
	 */
	public static function is_enable_data_layer_custom_event_name() {
		if ( null === static::$is_enable_data_layer_custom_event_name ) {
			static::$is_enable_data_layer_custom_event_name = GTM_SERVER_SIDE_FIELD_VALUE_YES === self::get_data_layer_custom_event_name();
		}

		return static::$is_enable_data_layer_custom_event_name;
	}

	/**
	 * Enable or disable GTM exclude roles.
	 *
	 * @return bool
	 */
	public static function is_enable_gtm_exclude_roles() {
		if ( null === static::$is_enable_gtm_exclude_roles ) {
			static::$is_enable_gtm_exclude_roles = GTM_SERVER_SIDE_FIELD_VALUE_YES === self::get_option( GTM_SERVER_SIDE_FIELD_GTM_EXCLUDE_ROLES );
		}

		return static::$is_enable_gtm_exclude_roles;
	}

	/**
	 * Return the sanitized GA4 Measurement ID the recovery fallback sends to.
	 *
	 * This value is also the switch (2.1.0): GA4 measurement recovery is on
	 * exactly when it is non-empty. When on, a small watchdog script ships with
	 * the custom loader: if the Google tag stays silent after the web container
	 * has loaded - which happens in browser privacy modes such as iOS Safari
	 * Private Browsing - the watchdog sends minimal first-party hits (the page
	 * view plus the plugin's data-layer events, ecommerce included) through
	 * the server container so the visit still reaches GA4.
	 *
	 * @return string Empty string when unset or not "G-..." shaped (feature off).
	 */
	public static function get_ga4_fallback_id() {
		$value = strtoupper( preg_replace( '/[^A-Za-z0-9-]/', '', (string) self::get_option( GTM_SERVER_SIDE_FIELD_GA4_FALLBACK_ID ) ) );

		return ( 0 === strpos( $value, 'G-' ) && strlen( $value ) > 2 ) ? $value : '';
	}

	/**
	 * Version string for an enqueued asset.
	 *
	 * WordPress appends this to the URL as "?ver=", and that is what busts a
	 * browser or CDN cache. Using the plugin version alone means a changed
	 * file behind an unchanged version number keeps serving the old bytes -
	 * which is precisely the situation while a release is being corrected,
	 * and precisely when the change matters. The content hash moves whenever
	 * the file does, so the version number is free to stay where it is.
	 *
	 * Falls back to the plugin version when the file cannot be read, which is
	 * the behaviour this replaces.
	 *
	 * @param  string $rel Path relative to the plugin root.
	 * @return string
	 */
	public static function get_asset_version( $rel ) {
		$query = self::get_asset_version_query( $rel );

		if ( '' === $query ) {
			return get_gtm_server_side_version();
		}

		return get_gtm_server_side_version() . '.' . substr( $query, 3 );
	}

	/**
	 * Build a "?v=<hash>" cache-busting query for a plugin asset.
	 *
	 * Content-hashed rather than version-stamped so a hand-patched asset also
	 * busts, and memoized per request. Returns '' when the file is unreadable -
	 * the caller then falls back to the plugin version, which still works.
	 *
	 * A persistent memo (transient, one week) avoids re-hashing the enqueued
	 * assets on every page view. Cache-busting correctness beats the
	 * micro-optimisation, so the stored hash is only ever trusted when the
	 * file's CURRENT mtime AND size both equal the values recorded beside it:
	 * a replaced, upgraded or hand-patched file changes its mtime, misses the
	 * memo, and is re-hashed on the spot. A missing transient, a mismatched or
	 * malformed value, or an unavailable transient API all fall through to
	 * hashing the file directly - byte-identical to the pre-memo behaviour.
	 * A failed hash is never stored, so an error can not stick.
	 *
	 * @param string $rel Path relative to the plugin root, e.g. "assets/js/javascript.js".
	 * @return string
	 */
	private static function get_asset_version_query( $rel ) {
		static $cache = array();

		if ( ! isset( $cache[ $rel ] ) ) {
			$cache[ $rel ] = self::build_asset_version_query( $rel );
		}

		return $cache[ $rel ];
	}

	/**
	 * Uncached worker for get_asset_version_query(). See there for the
	 * staleness guarantee.
	 *
	 * @param string $rel Path relative to the plugin root.
	 * @return string
	 */
	private static function build_asset_version_query( $rel ) {
		$path = GTM_SERVER_SIDE_PATH . $rel;
		if ( ! is_readable( $path ) ) {
			return '';
		}

		$mtime = filemtime( $path );
		$size  = filesize( $path );
		$known = false !== $mtime && false !== $size;
		$key   = 'synapse_ct_av_' . md5( $rel );

		if ( $known && function_exists( 'get_transient' ) ) {
			$saved = get_transient( $key );
			if ( is_array( $saved )
				&& isset( $saved['m'], $saved['s'], $saved['q'] )
				&& $saved['m'] === $mtime
				&& $saved['s'] === $size
				&& is_string( $saved['q'] )
				&& 1 === preg_match( '/^\?v=[0-9a-f]{8}$/', $saved['q'] ) ) {
				return $saved['q'];
			}
		}

		$hash = hash_file( 'sha256', $path );
		if ( ! is_string( $hash ) ) {
			return '';
		}

		$query = '?v=' . substr( $hash, 0, 8 );

		if ( $known && function_exists( 'set_transient' ) ) {
			set_transient( $key, array( 'm' => $mtime, 's' => $size, 'q' => $query ), WEEK_IN_SECONDS );
		}

		return $query;
	}

	/**
	 * Check container placement is code or not.
	 *
	 * @return bool
	 */
	public static function is_enable_placement_code() {
		return GTM_SERVER_SIDE_FIELD_PLACEMENT_VALUE_CODE === self::get_option_container_placement();
	}

	/**
	 * Check container placement is GTM Consent or not.
	 *
	 * @return bool
	 */
	public static function is_enable_placement_gtm_consent() {
		return GTM_SERVER_SIDE_FIELD_PLACEMENT_VALUE_GTM_CONSENT === self::get_option_container_placement();
	}

	/**
	 * Set session.
	 *
	 * @param  mixed $name Name.
	 * @param  mixed $value Value.
	 * @return void
	 */
	public static function set_session( $name, $value ) {
		self::set_cookie(
			array(
				'name'     => $name,
				'value'    => $value,
				'secure'   => false,
				'samesite' => '',
			)
		);
	}

	/**
	 * Return session.
	 *
	 * @param  mixed $name Name.
	 * @param  mixed $default Default.
	 * @return mixed
	 */
	public static function get_session( $name, $default = null ) {
		if ( ! isset( $_COOKIE[ $name ] ) ) {
			return $default;
		}

		return filter_input( INPUT_COOKIE, $name, FILTER_DEFAULT );
	}

	/**
	 * Check exists session or not.
	 *
	 * @param  string $name Name.
	 * @param  mixed  $value Value.
	 * @return bool
	 */
	public static function exists_session( $name, $value ) {
		if ( ! isset( $_COOKIE[ $name ] ) ) {
			return false;
		}

		return $_COOKIE[ $name ] === $value;
	}

	/**
	 * Delete session.
	 *
	 * @param  string $name Name.
	 * @return void
	 */
	public static function delete_session( $name ) {
		if ( isset( $_COOKIE[ $name ] ) ) {
			self::delete_cookie( $name );
		}
	}

	/**
	 * Set cookie.
	 *
	 * @param  array $args Parameters.
	 * @return void
	 */
	public static function set_cookie( $args ) {
		$args = wp_parse_args(
			$args,
			self::get_default_cookie_options()
		);

		if ( version_compare( PHP_VERSION, '7.3.0', '>=' ) ) {
			$name  = $args['name'];
			$value = $args['value'];

			unset( $args['name'] );
			unset( $args['value'] );

			setcookie(
				$name,
				$value,
				$args
			);
		} else {
			setcookie(
				$args['name'],
				$args['value'],
				$args['expires'],
				$args['path'],
				$args['domain'],
				$args['secure'],
				$args['httponly']
			);
		}
	}

	/**
	 * Delete cookie.
	 *
	 * @param  string $name Name.
	 * @return void
	 */
	public static function delete_cookie( $name ) {
		self::set_cookie(
			array(
				'name'    => $name,
				'value'   => '',
				'expires' => -1,
			)
		);
		unset( $_COOKIE[ $name ] );
	}

	/**
	 * Return default cookie options.
	 *
	 * @return array
	 */
	private static function get_default_cookie_options() {
		return array(
			'name'     => '',
			'value'    => '',
			'expires'  => 0,
			'path'     => '/',
			'domain'   => '.' . wp_parse_url( home_url(), PHP_URL_HOST ),
			'secure'   => true,
			'httponly' => false,
			'samesite' => 'lax',
		);
	}

	/**
	 * Delete cookie using javascript.
	 *
	 * @param  string $name Name.
	 * @return void
	 */
	public static function javascript_delete_cookie( $name ) {
		$options = self::get_default_cookie_options();
		?>
			<script>
				document.cookie = '<?php echo esc_attr( $name ); ?>=; max-age=-1; path=<?php echo esc_attr( $options['path'] ); ?>; domain=<?php echo esc_attr( $options['domain'] ); ?>;';
			</script>
		<?php
	}

	/**
	 * Sanitize bool.
	 *
	 * @param  string $value Bool.
	 * @return string
	 */
	public static function sanitize_bool( $value ) {
		return 'yes' === $value ? 'yes' : '';
	}

	/**
	 * Check if GTM plugin is enabled.
	 *
	 * @return bool
	 */
	public static function is_plugin_gtm4wp_enabled() {
		self::include_functions_plugin();

		return is_plugin_active( 'duracelltomi-google-tag-manager/duracelltomi-google-tag-manager-for-wordpress.php' );
	}

	/**
	 * Check if WooCommerce plugin is enabled.
	 *
	 * @return bool
	 */
	public static function is_plugin_wc_enabled() {
		self::include_functions_plugin();

		return is_plugin_active( 'woocommerce/woocommerce.php' );
	}

	/**
	 * Include functions plugin
	 *
	 * @return void
	 */
	private static function include_functions_plugin() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * Return maybe in json format or not
	 *
	 * @param  mixed $data Data.
	 * @return mixed
	 */
	public static function array_to_json( $data ) {
		return wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
	}

	/**
	 * Return data layer event name.
	 *
	 * @param  string $event_name Event name.
	 * @return string
	 */
	public static function get_data_layer_event_name( $event_name ) {
		if ( self::is_enable_data_layer_custom_event_name() ) {
			return $event_name . GTM_SERVER_SIDE_DATA_LAYER_CUSTOM_EVENT_NAME;
		}
		return $event_name;
	}

	/**
	 * Return generated prefix for GTM container identifier.
	 *
	 * @param  int $min_length Min length.
	 * @param  int $max_length Max length.
	 * @return string
	 */
	public static function generate_gtm_container_identifier_prefix( $min_length, $max_length ) {
		$max_attempts = 1000;

		do {
			$random_string = self::generate_random_string( $min_length, $max_length );
			$valid         = ! preg_match( '/(kp|gt)$/i', $random_string );

			if ( $valid || --$max_attempts <= 0 ) {
				break;
			}
		} while ( true );

		return $random_string;
	}

	/**
	 * Return generated random string.
	 *
	 * @param  int $min_length Min length.
	 * @param  int $max_length Max length.
	 * @return string
	 */
	public static function generate_random_string( $min_length, $max_length ) {
		$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$length     = wp_rand( $min_length, $max_length );

		$random_string = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$random_string .= $characters[ wp_rand( 0, strlen( $characters ) - 1 ) ];
		}

		return $random_string;
	}

	/**
	 * The key of the loader's query (2.3.0): one to eight letters or digits as
	 * before, but never a name the workers pass on to Google with the loader
	 * request (l, gtm_debug, gtm_auth, gtm_preview, cx, gtm, sign, google_only:
	 * LOADER_PASS in the workers), nor one the loader or the disguise reads
	 * (id, ei), nor one of the query's own ends (cgm, asq, tl, type, sort) or
	 * marks (_synr). Under such a key the blob would travel to Google as that
	 * parameter (as "l", the data layer's name, it would break every tag of
	 * the container) or be read as something else. A reserved draw, in any
	 * letter case, is drawn again; a thousand draws at most.
	 *
	 * @param  int $attempts Draws left.
	 * @return string
	 */
	public static function generate_loader_query_key( $attempts = 1000 ) {
		$reserved = array( 'l', 'gtm_debug', 'gtm_auth', 'gtm_preview', 'cx', 'gtm', 'sign', 'google_only', 'id', 'ei', 'cgm', 'asq', 'tl', 'type', 'sort', '_synr' );
		$key      = self::generate_random_string( 1, 8 );
		if ( $attempts > 1 && in_array( strtolower( $key ), $reserved, true ) ) {
			return self::generate_loader_query_key( $attempts - 1 );
		}

		return $key;
	}

	/**
	 * Return a generated field: the random parts of the loader's address (the
	 * first letters of its file name, the key and the end of its query).
	 *
	 * The value is stored together with a fingerprint of the settings it was
	 * generated from, and is only reused while that fingerprint still
	 * matches. Invalidation used to depend entirely on update_option_ hooks
	 * that are registered inside the admin class, so anything that changed a
	 * setting without going through wp-admin - WP-CLI, a migration, an
	 * importer, delete_option followed by add_option - left the site serving
	 * the previous disguised file name for up to a year, with no way to tell
	 * from the settings screen.
	 *
	 * 2.3.0 (B-01 of the fifth review): the value is kept in an option of its
	 * own, autoloaded, and read from there. Up to 2.2.1 it lived in a
	 * transient alone, and on a site with a persistent object cache a
	 * transient is gone with every flush or eviction of that cache: the next
	 * page then drew a new address. Harmless while nothing was bound to the
	 * address; since 2.3.0 the shield check's "yes" is bound to it
	 * (GTM_Server_Side_Shield_Check), and the check's own purge may flush
	 * that very cache. In an option the address of a set
	 * of settings stays what it is, whatever happens to a cache, and a page
	 * reads it without a query.
	 *
	 * The transient is still read when the option has no value for these
	 * settings, and what it holds is taken over: the address 2.2.1 printed
	 * does not change with the update. A value made here is written to both,
	 * so 2.2.1, put back after a downgrade, finds it as well. The option is
	 * never deleted (the rollback rule of the upgrade class).
	 *
	 * A value written by an older version is a bare string and does not match
	 * the stored shape, so it is regenerated once and then behaves.
	 *
	 * @param  string   $key         Cache key.
	 * @param  callable $callback    Callback.
	 * @param  string   $fingerprint Inputs the generated value depends on.
	 * @return string
	 */
	public static function get_cache_field( $key, $callback, $fingerprint = '' ) {
		$key  = $key . '__generated';
		$kept = get_option( $key );

		if ( self::is_generated( $kept, $fingerprint ) ) {
			return $kept['v'];
		}

		$cache = get_transient( $key );

		if ( self::is_generated( $cache, $fingerprint ) ) {
			$value = $cache['v'];
		} else {
			$value = call_user_func( $callback );
			set_transient( $key, array( 'f' => $fingerprint, 'v' => $value ), YEAR_IN_SECONDS );
		}

		update_option( $key, array( 'f' => $fingerprint, 'v' => $value ), true );

		return $value;
	}

	/**
	 * Is this a stored generated value, made for these settings? An array
	 * with the fingerprint asked for and a value that is a text and not
	 * empty; anything else (nothing stored, a bare string of 2.0.x, another
	 * fingerprint, a value emptied by delete_cache_field()) is not.
	 *
	 * @param  mixed  $stored      What the option or the transient holds.
	 * @param  string $fingerprint Inputs the generated value depends on.
	 * @return bool
	 */
	private static function is_generated( $stored, $fingerprint ) {
		return is_array( $stored ) && isset( $stored['f'], $stored['v'] ) && $stored['f'] === $fingerprint && is_string( $stored['v'] ) && '' !== $stored['v'];
	}

	/**
	 * Drop a generated field, so the next read draws a new one: the
	 * transient is deleted, as before, and the kept value is emptied (the
	 * option stays, emptied: never deleted).
	 *
	 * @param  string $key Cache key.
	 * @return void
	 */
	public static function delete_cache_field( $key ) {
		delete_transient( $key . '__generated' );

		if ( false !== get_option( $key . '__generated' ) ) {
			update_option( $key . '__generated', array(), true );
		}
	}
}
