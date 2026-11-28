<?php
/**
 * Self-update through WordPress's own update system.
 *
 * @package    GTM_Server_Side
 * @subpackage GTM_Server_Side/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Self-update through WordPress's own update system.
 *
 * WordPress only updates plugins it can ask about. For plugins that are not
 * on wordpress.org it offers, since 5.8, the "Update URI" plugin header: core
 * takes the hostname from it and, on every update check it already performs,
 * runs the filter "update_plugins_{hostname}" for that plugin. Whatever the
 * filter returns with a newer version is listed under Plugins and Dashboard >
 * Updates and installed from its "package" URL through the ordinary one-click
 * or automatic update flow. No library, no second mechanism.
 *
 * This class answers that filter by fetching a small JSON manifest from the
 * Update URI itself, so the address lives exactly once, in the plugin header.
 * Nothing else is contacted and nothing is sent that core does not send
 * anyway. Every failure is silent by design: an unreachable manifest, bad
 * JSON, a package on another host or over plain HTTP, a signature that does
 * not verify, or anything else unexpected simply means "no update
 * available", never an error on the site.
 *
 * The signature is checked twice and can never be skipped. A manifest is
 * believed only when its release record verifies, so a hostile update channel
 * cannot even put an offer on the screen. The record behind the offer is kept
 * network-wide, apart from the manifest cache, and every download of this
 * plugin - whoever asks for it and whatever another filter already answered -
 * must match that record byte for byte before WordPress installs it, even when
 * the manifest is missing or names another package at download time.
 */
class GTM_Server_Side_Plugin_Update {
	use GTM_Server_Side_Singleton;

	const SLUG      = 'synapse-server-side-tracking';
	const TRANSIENT = 'synapse_ct_update_manifest';
	const OFFER     = 'synapse_ct_update_offer';
	const TTL       = 6 * HOUR_IN_SECONDS;
	const RETRY     = HOUR_IN_SECONDS;

	/**
	 * Packages live under this path on the manifest host. raw.githubusercontent.com
	 * serves every GitHub repository, so the host alone says little.
	 */
	const PACKAGE_PATH = '/synapse-marketing/synapse-server-side-tracking/';

	/**
	 * A release is about 100 KB; anything this large is not one.
	 */
	const MAX_BYTES = 20971520;

	/**
	 * Ed25519 public keys of the release signing keys, raw 32 bytes, base64,
	 * by key id.
	 *
	 * k1 signs releases. k2 is a spare whose private half is kept offline: if
	 * k1 is ever lost or exposed, a release signed with k2 still installs,
	 * and that release can retire k1. The private halves never leave the
	 * release machine and its escrow. Every package is signed over
	 * "synapse-update/3\n<slug>\n<version>\n<sha256>\n<bytes>\n<package URL>\n<issued>\n<min safe version>"
	 * which binds the plugin, the version, the exact bytes and the address: an
	 * old, genuinely signed zip cannot be re-labelled as a newer version,
	 * offered for another plugin or served from somewhere else. The record
	 * carries the day it is issued and the lowest version without a known
	 * security fix; a record dated before the one this site already saw is not
	 * believed (no way back to an older record), and the plugins screen warns
	 * while this site runs a version below the safe one. Records never expire:
	 * a customer sees every release for life, so nothing has to be re-signed on
	 * a schedule.
	 */
	const KEYS = array(
		'k1' => 'GRuGzVdywPtC1a4Ws/2YcPdJwNsjJzbWiuJbIHPVvGo=',
		'k2' => 'xjv0RlMGW5wzypxbZw9EqCsAVyxi5hrBca+ZhRpPT/c=',
	);

	/**
	 * Init.
	 *
	 * @return void
	 */
	public function init() {
		$host = wp_parse_url( self::update_uri(), PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return;
		}

		add_filter( 'update_plugins_' . $host, array( $this, 'check' ), 10, 4 );
		add_filter( 'plugins_api', array( $this, 'details' ), 10, 3 );
		// Late, so that a cache or proxy answering this filter for our package is still checked.
		add_filter( 'upgrader_pre_download', array( $this, 'verify_download' ), 1000, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'forget' ), 10, 2 );
		// Before core's own check on that screen (priority 10), so "Check again" really asks.
		add_action( 'load-update-core.php', array( $this, 'forget' ), 1 );
		add_action( 'admin_notices', array( $this, 'safety_notice' ) );
	}

	/**
	 * Whether this site runs a version below the lowest safe one named by the last signed record.
	 *
	 * @return bool
	 */
	public function needs_safety_update() {
		$offer = get_site_option( self::OFFER );
		$min   = ( is_array( $offer ) && isset( $offer['min_safe'] ) && is_string( $offer['min_safe'] ) ) ? $offer['min_safe'] : '';
		return '' !== $min && version_compare( self::installed(), $min, '<' );
	}

	/**
	 * The warning on the admin screens while the installed version is below the safe one.
	 *
	 * @return void
	 */
	public function safety_notice() {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'update_plugins' ) || ! $this->needs_safety_update() ) {
			return;
		}
		$offer = get_site_option( self::OFFER );
		echo '<div class="notice notice-warning"><p><strong>' . esc_html( sprintf( 'Synapse Server-Side Tracking %s or newer has a security fix.', (string) $offer['min_safe'] ) ) . '</strong> ' . esc_html( sprintf( 'This site runs %s. Please update the plugin under Plugins.', self::installed() ) ) . '</p></div>';
	}

	/**
	 * Answer WordPress's update check for this plugin.
	 *
	 * @param  false|array $update      What core has so far, always false.
	 * @param  array       $plugin_data Header data of the installed plugin.
	 * @param  string      $plugin_file Plugin basename.
	 * @param  string[]    $locales     Site locales.
	 * @return false|array
	 */
	public function check( $update, $plugin_data, $plugin_file, $locales ) {
		if ( self::basename() !== $plugin_file ) {
			return $update;
		}

		$m = $this->manifest();
		if ( ! $m ) {
			return $update;
		}

		// The record this offer stands on. verify_download() trusts nothing else.
		update_site_option( self::OFFER, $m );

		return array(
			'id'           => isset( $plugin_data['UpdateURI'] ) ? $plugin_data['UpdateURI'] : self::update_uri(),
			'slug'         => self::SLUG,
			'plugin'       => $plugin_file,
			'version'      => $m['version'],
			'new_version'  => $m['version'],
			'url'          => $m['homepage'],
			'package'      => $m['package'],
			'requires'     => $m['requires'],
			'tested'       => $m['tested'],
			'requires_php' => $m['requires_php'],
			'icons'        => array(),
			'banners'      => array(),
			'banners_rtl'  => array(),
		);
	}

	/**
	 * Fill the "View version details" window from the same verified manifest.
	 *
	 * @param  false|object|array $result Result so far.
	 * @param  string             $action API action.
	 * @param  object             $args   Arguments.
	 * @return false|object|array
	 */
	public function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$m = $this->manifest();
		if ( ! $m ) {
			return $result;
		}

		$sections = array();
		foreach ( $m['sections'] as $key => $html ) {
			if ( is_string( $html ) ) {
				$sections[ sanitize_key( $key ) ] = wp_kses_post( $html );
			}
		}

		return (object) array(
			'name'          => 'Synapse Server-Side Tracking',
			'slug'          => self::SLUG,
			'version'       => $m['version'],
			'author'        => 'Synapse',
			'homepage'      => $m['homepage'],
			'requires'      => $m['requires'],
			'tested'        => $m['tested'],
			'requires_php'  => $m['requires_php'],
			'last_updated'  => $m['last_updated'],
			'download_link' => $m['package'],
			'sections'      => $sections,
		);
	}

	/**
	 * Refuse any download of this plugin that is not the signed package.
	 *
	 * WordPress verifies nothing about packages that do not come from
	 * wordpress.org. This hook runs before the upgrader downloads; returning a
	 * file path makes it install that file, returning a WP_Error stops the
	 * update with that message. A download is ours when core names this plugin
	 * (single, bulk and automatic updates all do) or when it asks for the
	 * package of the stored offer; every other update passes through untouched.
	 *
	 * For ours there is no way around the check: no signed record for this
	 * exact package, a version that is not newer than the installed one, bytes
	 * that are not the signed ones, or a signature that does not hold all end
	 * with nothing installed - including when another filter already produced
	 * a file for it. A silent fallback to "install it anyway" would make the
	 * signature decorative.
	 *
	 * @param  false|string|WP_Error $reply      What an earlier filter decided.
	 * @param  string                $package    Package URL.
	 * @param  WP_Upgrader           $upgrader   Upgrader instance.
	 * @param  array                 $hook_extra Extra arguments.
	 * @return false|string|WP_Error
	 */
	public function verify_download( $reply, $package, $upgrader, $hook_extra = array() ) {
		if ( is_wp_error( $reply ) || ! $this->is_ours( $package, $hook_extra ) ) {
			return $reply;
		}

		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return $this->refuse( 'no_sodium' );
		}

		$m = $this->record_for( (string) $package );
		if ( ! $m ) {
			return $this->refuse( 'unsigned' );
		}
		if ( version_compare( $m['version'], self::installed(), '<=' ) ) {
			return $this->refuse( 'bad_signature' );
		}

		$file = ( is_string( $reply ) && '' !== $reply ) ? $reply : $this->fetch( (string) $package );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		if ( ! $this->file_matches( $file, $m ) ) {
			if ( $file !== $reply ) {
				$this->discard( $file );
			}
			return $this->refuse( 'bad_signature' );
		}

		return $file;
	}

	/**
	 * Drop the cached manifest, so the next check is a real one.
	 *
	 * Runs after any plugin update (the argument shape core passes) and when
	 * the operator opens Dashboard > Updates (no arguments). The stored offer
	 * stays: it is the record the pending update is offered on.
	 *
	 * @return void
	 */
	public function forget() {
		delete_site_transient( self::TRANSIENT );
	}

	/**
	 * Whether this download is an update of this plugin.
	 *
	 * @param  string $package    Package URL or path.
	 * @param  mixed  $hook_extra What core passes along with it.
	 * @return bool
	 */
	private function is_ours( $package, $hook_extra ) {
		if ( is_array( $hook_extra ) && isset( $hook_extra['plugin'] ) && self::basename() === $hook_extra['plugin'] ) {
			return true;
		}

		$offer = get_site_option( self::OFFER );
		return is_array( $offer ) && isset( $offer['package'] ) && (string) $package === $offer['package'];
	}

	/**
	 * The signed release record for exactly this package: the stored offer
	 * first, then the current manifest.
	 *
	 * @param  string $package Package URL.
	 * @return array|null
	 */
	private function record_for( $package ) {
		$offer = get_site_option( self::OFFER );
		if ( is_array( $offer ) && isset( $offer['package'] ) && $package === $offer['package'] ) {
			return $offer;
		}

		$m = $this->manifest();
		if ( $m && $package === $m['package'] ) {
			return $m;
		}

		return null;
	}

	/**
	 * Whether a downloaded file is byte for byte the signed package.
	 *
	 * @param  string $file Local path.
	 * @param  array  $m    Release record.
	 * @return bool
	 */
	private function file_matches( $file, $m ) {
		$hash = hash_file( 'sha256', $file );
		$size = filesize( $file );

		return is_string( $hash )
			&& hash_equals( $m['sha256'], $hash )
			&& (int) $size === (int) $m['bytes']
			&& $this->verify_sig( $m['signature_v3'], self::message( $m ), $m['kid'] );
	}

	/**
	 * The manifest, fetched at most once per TTL, network-wide.
	 *
	 * A failed or unverified fetch is remembered for a shorter while, so an
	 * outage of the update host does not turn into a request on every page
	 * load.
	 *
	 * @return array|null
	 */
	private function manifest() {
		$cached = get_site_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached ? $cached : null;
		}

		$m = $this->accept( $this->fetch_manifest() );
		set_site_transient( self::TRANSIENT, $m ? $m : array(), $m ? self::TTL : self::RETRY );

		return $m;
	}

	/**
	 * A decoded manifest as a release record, or null.
	 *
	 * Only a manifest whose release record verifies is believed: the version,
	 * the exact bytes and an HTTPS package under our own path on the manifest
	 * host, signed by a known key. Every field that reaches an admin screen is
	 * reduced to the plain form it is supposed to have.
	 *
	 * @param  mixed $j Decoded manifest.
	 * @return array|null
	 */
	private function accept( $j ) {
		if ( ! is_array( $j ) ) {
			return null;
		}
		if ( isset( $j['slug'] ) && self::SLUG !== $j['slug'] ) {
			return null;
		}

		$version = self::plain( $j, 'version', '/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/' );
		$sha     = self::plain( $j, 'package_sha256', '/^[0-9a-f]{64}$/' );
		$bytes   = ( isset( $j['package_bytes'] ) && is_int( $j['package_bytes'] ) ) ? $j['package_bytes'] : 0;
		$package = ( isset( $j['package'] ) && is_string( $j['package'] ) ) ? $j['package'] : '';
		if ( '' === $version || '' === $sha || $bytes < 1 || $bytes > self::MAX_BYTES || ! self::package_ok( $package ) ) {
			return null;
		}

		// The record's day and the lowest safe version are signed with everything else (format 3); a record dated
		// before the one this site already saw is not believed (no way back to an older record); never expires.
		$issued = self::plain( $j, 'issued', '/^\d{4}-\d{2}-\d{2}$/' );
		$safe   = self::plain( $j, 'min_safe_version', '/^\d+\.\d+\.\d+$/' );
		if ( '' === $safe || '' === $issued || ! strtotime( $issued . 'T00:00:00Z' ) ) {
			return null;
		}
		$seen = get_site_option( self::OFFER );
		if ( is_array( $seen ) && isset( $seen['issued'] ) && is_string( $seen['issued'] ) && strcmp( $seen['issued'], $issued ) > 0 ) {
			return null;
		}

		$m = array(
			'version'      => $version,
			'package'      => $package,
			'sha256'       => $sha,
			'bytes'        => $bytes,
			'issued'       => $issued,
			'min_safe'     => $safe,
			'signature_v3' => ( isset( $j['signature_v3'] ) && is_string( $j['signature_v3'] ) ) ? $j['signature_v3'] : '',
			'kid'          => ( isset( $j['signature_v3_kid'] ) && is_string( $j['signature_v3_kid'] ) ) ? $j['signature_v3_kid'] : '',
		);
		if ( ! $this->verify_sig( $m['signature_v3'], self::message( $m ), $m['kid'] ) ) {
			return null;
		}

		$m['homepage']     = ( isset( $j['homepage'] ) && is_string( $j['homepage'] ) ) ? esc_url_raw( $j['homepage'] ) : '';
		$m['requires']     = self::plain( $j, 'requires', '/^\d+(\.\d+){0,2}$/' );
		$m['tested']       = self::plain( $j, 'tested', '/^\d+(\.\d+){0,2}$/' );
		$m['requires_php'] = self::plain( $j, 'requires_php', '/^\d+(\.\d+){0,2}$/' );
		$m['last_updated'] = self::plain( $j, 'last_updated', '/^\d{4}-\d{2}-\d{2}$/' );
		$m['sections']     = ( isset( $j['sections'] ) && is_array( $j['sections'] ) ) ? $j['sections'] : array();

		return $m;
	}

	/**
	 * The signed message of a release record.
	 *
	 * @param  array $m Release record.
	 * @return string
	 */
	private static function message( $m ) {
		return "synapse-update/3\n" . self::SLUG . "\n" . $m['version'] . "\n" . $m['sha256'] . "\n" . (string) $m['bytes'] . "\n" . $m['package'] . "\n" . $m['issued'] . "\n" . $m['min_safe'];
	}

	/**
	 * Verify an Ed25519 signature with the baked key it names.
	 *
	 * @param  string $signature Base64 signature.
	 * @param  string $message   Signed message.
	 * @param  string $kid       Key id.
	 * @return bool
	 */
	private function verify_sig( $signature, $message, $kid ) {
		$keys = self::KEYS;
		if ( ! is_string( $signature ) || ! is_string( $kid ) || ! isset( $keys[ $kid ] ) ) {
			return false;
		}

		$sig = base64_decode( $signature, true );
		$key = base64_decode( $keys[ $kid ], true );
		if ( false === $sig || 64 !== strlen( $sig ) || false === $key || 32 !== strlen( $key ) ) {
			return false;
		}

		return $this->sodium_verify( $sig, $message, $key );
	}

	/**
	 * The one sodium call, so that a malformed input is a refusal, not a fatal error.
	 *
	 * @param  string $sig     Raw 64-byte signature.
	 * @param  string $message Signed message.
	 * @param  string $key     Raw 32-byte public key.
	 * @return bool
	 */
	private function sodium_verify( $sig, $message, $key ) {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return false;
		}
		try {
			return sodium_crypto_sign_verify_detached( $sig, $message, $key );
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * Fetch and decode the manifest from the Update URI.
	 *
	 * @return mixed Decoded JSON, or null.
	 */
	private function fetch_manifest() {
		$uri = self::update_uri();
		if ( '' === $uri ) {
			return null;
		}

		$res = wp_remote_get(
			$uri,
			array(
				'timeout'             => 8,
				'limit_response_size' => 1048576,
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return null;
		}

		return json_decode( wp_remote_retrieve_body( $res ), true );
	}

	/**
	 * Download a package to a temporary file.
	 *
	 * @param  string $url Package URL.
	 * @return string|WP_Error
	 */
	private function fetch( $url ) {
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return download_url( $url, 60 );
	}

	/**
	 * Remove a refused download.
	 *
	 * @param  string $file Local path.
	 * @return void
	 */
	private function discard( $file ) {
		@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * The error that stops a refused update.
	 *
	 * @param  string $why no_sodium, unsigned or bad_signature.
	 * @return WP_Error
	 */
	private function refuse( $why ) {
		if ( 'no_sodium' === $why ) {
			return new WP_Error( 'synapse_ct_no_sodium', __( 'Synapse Server-Side Tracking: this update is signed and PHP has no sodium extension to verify it with. Nothing was installed.', 'gtm-server-side' ) );
		}
		if ( 'unsigned' === $why ) {
			return new WP_Error( 'synapse_ct_unsigned', __( 'Synapse Server-Side Tracking: the update manifest carries no signature. Nothing was installed.', 'gtm-server-side' ) );
		}
		return new WP_Error( 'synapse_ct_bad_signature', __( 'Synapse Server-Side Tracking: the downloaded package did not match its signature. Nothing was installed.', 'gtm-server-side' ) );
	}

	/**
	 * A string field of the manifest in its plain form, or ''.
	 *
	 * @param  array  $j   Decoded manifest.
	 * @param  string $key Field.
	 * @param  string $re  The whole value must match this.
	 * @return string
	 */
	private static function plain( $j, $key, $re ) {
		if ( ! isset( $j[ $key ] ) || ! is_string( $j[ $key ] ) ) {
			return '';
		}
		$v = $j[ $key ];
		return ( trim( $v ) === $v && preg_match( $re, $v ) ) ? $v : '';
	}

	/**
	 * An HTTPS package under our own path on the manifest host.
	 *
	 * @param  string $package Package URL.
	 * @return bool
	 */
	private static function package_ok( $package ) {
		$pkg = wp_parse_url( $package );
		$src = wp_parse_url( self::update_uri() );
		if ( ! is_array( $pkg ) || ! is_array( $src ) || ! isset( $pkg['scheme'] ) || ! isset( $pkg['host'] ) || ! isset( $pkg['path'] ) || ! isset( $src['host'] ) ) {
			return false;
		}

		return 'https' === strtolower( $pkg['scheme'] )
			&& strtolower( $pkg['host'] ) === strtolower( $src['host'] )
			&& 0 === strpos( $pkg['path'], self::PACKAGE_PATH )
			&& false === strpos( $pkg['path'], '..' );
	}

	/**
	 * The installed version.
	 *
	 * @return string
	 */
	private static function installed() {
		return (string) get_gtm_server_side_version();
	}

	/**
	 * The Update URI, read from the plugin header so it lives in one place.
	 *
	 * @return string
	 */
	private static function update_uri() {
		static $uri;

		if ( null === $uri ) {
			$data = get_file_data( GTM_SERVER_SIDE_PATH . 'synapse-server-side-tracking.php', array( 'uri' => 'Update URI' ), false );
			$uri  = isset( $data['uri'] ) ? esc_url_raw( trim( $data['uri'] ) ) : '';
			if ( 0 !== strpos( $uri, 'https://' ) ) {
				$uri = '';
			}
		}

		return $uri;
	}

	/**
	 * Plugin basename, e.g. "synapse-server-side-tracking/synapse-server-side-tracking.php".
	 *
	 * @return string
	 */
	private static function basename() {
		return plugin_basename( GTM_SERVER_SIDE_PATH . 'synapse-server-side-tracking.php' );
	}
}
