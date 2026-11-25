<?php
/**
 * The shield check (2.3.0).
 *
 * Once an hour WP-Cron asks the loader address itself whether the protection
 * (the blocker probe, the ad-blocker shim, the keepalive helper and the GA4
 * watchdog) is composed into the loader script there: a HEAD of the very
 * address a page printed for the loader asks for,
 * <container URL>/<identifier>.js?<the p1 query>, must answer 204 with the
 * header X-Synapse-Shield: p1;<worker version>;<hash>, where the hash is the
 * one this plugin computes from its own four pieces (pieces_hash() below):
 * the worker composes its shield from a copy of this plugin's code, and a
 * worker built from another version of the pieces says another hash and gets
 * a "no". A worker with the shield answers that HEAD itself, before its bot
 * gate and without fetching Google's script; a worker without it answers
 * with Google's script (a 200), which is a "no". So the check proves the
 * route the loader itself takes, with the values the page itself sends, not
 * a probe route of its own. The HEAD goes out through wp_safe_remote_head(),
 * which refuses an address or a redirect that points at a local or private
 * host.
 *
 * The answer is kept in ONE autoloaded option with an expiry and the md5 of
 * the address asked, so a page never pays a query for it. "yes" lets the page
 * print the loader line alone and leave the rest to the loader, but only
 * while the address a page would ask is the address the "yes" was given for:
 * a value changed under the record (a settings save, an importer, a migration,
 * an answer that arrives after a reset) prints inline until the next run says
 * "yes" for the new address. "no", an expired or unbound record, no record at
 * all, and a value the worker's parser would refuse or read back short (the
 * contract below) keep everything inline, exactly as 2.2.1 printed it.
 *
 * A "yes" holds 90 minutes and is renewed hourly, so a stalled WP-Cron falls
 * back to inline on its own. A request that gets no answer at all (a timeout,
 * a refused connection) proves nothing either way: the record stays as it is
 * and runs out on its own time. So does a 5xx or a 429 (Cloudflare's Error
 * 1027 of a spent daily allowance among them): the edge or a server in
 * trouble says nothing about the shield, and the loader fails for the visitor
 * whatever the page printed. Any other answer than the 204 with the header
 * is a "no"; the 10-minute stamp on it changes nothing when it runs out (a
 * "no" and no record print the same), the next ask is the hourly run. A
 * changed container address, identifier, container id, GA4 id or event-name
 * setting resets the record and asks again at once, and so does a changed
 * snippet placement (under "Disable" or "Plugin" no page prints the loader:
 * nothing is asked, no event is kept); a plugin version change drops the
 * record and the next run asks again; activation drops whatever a previous
 * life left; deactivation drops it and clears the event.
 *
 * A dropped record carries a generation count one higher, and a run stores
 * its answer only under the generation it asked under, read fresh from the
 * database: an answer that arrives after the record was dropped (a reset, an
 * update, a deactivation while the three seconds ran; for this address or
 * another, or the same address set, changed and set back) is not stored; the
 * run the reset scheduled asks again. WordPress options know no compare-and-
 * set, so the moment between that fresh read and the write stays open, a
 * handful of microseconds instead of the three seconds of the request.
 *
 * Whenever a record that said "yes" gives way to a "no" (a "no" after a "yes",
 * live or run out; a reset; deactivation), the page caches this plugin knows
 * are purged, so no cached page printed for the loader keeps relying on one
 * that may no longer bring the protection: WP Rocket, WP Super Cache, W3 Total
 * Cache and LiteSpeed Cache, and the object cache only where none of the four
 * is found (the last resort). A "yes" dropped without a purge
 * (a version change, an activation) is remembered in the record until the next
 * "no", reset or deactivation purges for it, so the purge is never lost on the
 * way; the note of a purge goes on the record the purge was decided for, never
 * over one written while the purge ran. The action synapse_ct_purge_page_caches
 * fires with every purge, for a cache this plugin does not know (a host cache,
 * Cloudflare APO, a Cache Rule): those are the owner's to purge. No setting, no
 * visible text.
 *
 * The other way round, a "yes" that follows anything but a "yes" (the first
 * one, one after a "no", after a reset, a version change, an activation)
 * purges the same caches once, after the record is written (the owner's
 * decision of 9 Nov 2026): the pages cached until then carry the whole block
 * inline, correct but long, and would stay so until their cache ran out. A
 * "yes" that follows a "yes", live or run out, purges nothing, so the hourly
 * renewal and a stalled WP-Cron never purge.
 *
 * A page view reads the record and the options that hold the loader's own
 * address, all autoloaded, and never asks the network. The scheduling
 * on init writes the cron option once, when the event is missing (the first
 * load after activation, after a reset or after an update); the event is kept
 * wherever a page prints the custom loader over HTTPS under a path, and
 * cleared anywhere else.
 *
 * @package	   GTM_Server_Side
 * @subpackage GTM_Server_Side/includes
 * @since	   2.3.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * The shield check.
 */
class GTM_Server_Side_Shield_Check {
	use GTM_Server_Side_Singleton;

	/**
	 * The WP-Cron hook.
	 */
	const HOOK = 'synapse_ct_shield_check';

	/**
	 * The option holding the record:
	 * array( 'v' => 'yes'|'no', 'until' => <unix time>, 'fp' => <md5 of the address asked, '' for a "no">,
	 *        'g' => <generation: counted up each time the record is dropped>,
	 *        'y' => 1 while pages printed for the loader may sit in a page cache, 0 once purged ).
	 * A record without 'g' or 'y' (written by the build reviewed on 4 Nov 2026) reads as generation 0 and, when it
	 * says "yes", as pages that may be cached. A key this version does not write is never read.
	 */
	const OPTION = 'synapse_ct_shield';

	/**
	 * The answer header the loader address must carry on the HEAD.
	 */
	const HEADER = 'x-synapse-shield';

	/**
	 * How long a "yes" holds, in seconds (an hourly renewal plus slack).
	 */
	const TTL_YES = 5400;

	/**
	 * The stamp on a "no", in seconds (it running out changes nothing).
	 */
	const TTL_NO = 600;

	/**
	 * Seconds until the first hourly run once there is an address to ask.
	 */
	const DELAY = 60;

	/**
	 * The worker's contract for the values the loader's query carries
	 * (decodeLoaderValues in the workers: one rule on both sides; the worker
	 * suite compares these with the workers' constants): a container id of
	 * up to 15 letters or digits after "GTM-", in any letter case, or none;
	 * a GA4 id of up to 38 letters, digits or dashes after "G-", or none; an
	 * event-name suffix of up to 20 letters, digits or "_"; the whole blob
	 * printable ASCII of at most 256 bytes. A value the worker would refuse
	 * is never asked for: such a page prints inline.
	 *
	 * ID_CUT_RE is the loader's own rule for the container id a query names
	 * (decodeContainerId in the workers, unchanged since 1.1 and v5.5), which
	 * reads upper-case letters and digits after "GTM-" as far as they go: an
	 * id it would read short ("GTM-Nqhqhzlr" reads as "GTM-N", a container
	 * the client does not have, so the loader refuses the request the page
	 * printed) is never asked for either. Since the id is read in upper case
	 * (get_raw_gtm_container_id, 2.3.0) no id typed on the settings screen
	 * can match it; it stays as the mirror of the loader's rule.
	 */
	const ID_RE		= '/^GTM-[A-Z0-9]{1,15}$/i';
	const ID_CUT_RE = '/^GTM-[A-Z0-9]*[^A-Z0-9]/';
	const GA4_ID_RE = '/^G-[A-Z0-9-]{1,38}$/';
	const SUFFIX_RE = '/^[A-Za-z0-9_]{0,20}$/';
	const BLOB_RE	= '/^[\x21-\x7e]+$/';
	const BLOB_MAX	= 256;

	/**
	 * The markers around the body of each of the four piece methods in the
	 * tracking-code file (pieces_hash() below; shield-build.mjs reads the
	 * same markers).
	 */
	const PIECE_BEGIN = '// ---- SHIELD PIECE BEGIN ----';
	const PIECE_END = '// ---- SHIELD PIECE END ----';
	const PIECES_FILE = 'includes/class-gtm-server-side-tracking-code.php';

	/**
	 * Init.
	 *
	 * @return void
	 */
	public function init() {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( 'update_option_' . GTM_SERVER_SIDE_FIELD_WEB_CONTAINER_URL, array( $this, 'reset' ) );
		add_action( 'update_option_' . GTM_SERVER_SIDE_FIELD_WEB_IDENTIFIER, array( $this, 'reset' ) );
		add_action( 'update_option_' . GTM_SERVER_SIDE_FIELD_WEB_CONTAINER_ID, array( $this, 'reset' ) );
		add_action( 'update_option_' . GTM_SERVER_SIDE_FIELD_GA4_FALLBACK_ID, array( $this, 'reset' ) );
		add_action( 'update_option_' . GTM_SERVER_SIDE_FIELD_DATA_LAYER_CUSTOM_EVENT_NAME, array( $this, 'reset' ) );
		add_action( 'update_option_' . GTM_SERVER_SIDE_FIELD_PLACEMENT, array( $this, 'reset' ) );
	}

	/**
	 * Is the shield known to be served right now, for the address this page
	 * would ask?
	 *
	 * One autoloaded option read, then the address this page would ask
	 * (address(): the loader's random parts, each in an autoloaded option of
	 * its own since B-01 of the fifth review, so no query; a transient is
	 * read only where such an option holds nothing for the settings, once).
	 * Anything but a live "yes" bound to the current address is no.
	 *
	 * @return bool
	 */
	public static function is_served() {
		$record = get_option( self::OPTION );
		if ( ! self::live( $record ) ) {
			return false;
		}

		$address = self::address();

		return '' !== $address && $record['fp'] === md5( $address );
	}

	/**
	 * A well-formed "yes" that has not run out (its binding is checked by the
	 * caller).
	 *
	 * @param  mixed $record The option's value.
	 * @return bool
	 */
	private static function live( $record ) {
		return is_array( $record ) && isset( $record['v'], $record['until'], $record['fp'] ) && 'yes' === $record['v'] && is_string( $record['fp'] ) && (int) $record['until'] > time();
	}

	/**
	 * Did the record say "yes" at all (live, run out or unbound)? Such a
	 * record means pages printed for the loader may sit in a page cache.
	 *
	 * @param  mixed $record The option's value.
	 * @return bool
	 */
	private static function said_yes( $record ) {
		return is_array( $record ) && isset( $record['v'] ) && 'yes' === $record['v'];
	}

	/**
	 * The generation of a record: counted up each time the record is dropped
	 * (a reset, a version change, an activation, a deactivation), so a run
	 * stores its answer only under the generation it asked under.
	 *
	 * @param  mixed $record The option's value.
	 * @return int
	 */
	private static function generation( $record ) {
		return is_array( $record ) && isset( $record['g'] ) ? (int) $record['g'] : 0;
	}

	/**
	 * May pages printed for the loader sit in a page cache? Yes while the
	 * record says "yes" (such pages are printed now) and after a "yes" was
	 * dropped or gave way to a "no" without a purge since (the record keeps
	 * that in 'y' until a purge clears it), so a purge is never lost on the
	 * way from a "yes" to the "no", reset or deactivation that follows.
	 *
	 * @param  mixed $record The option's value.
	 * @return bool
	 */
	private static function may_cache( $record ) {
		return self::said_yes( $record ) || ( is_array( $record ) && isset( $record['y'] ) && 1 === (int) $record['y'] );
	}

	/**
	 * The record as the database holds it now, not as this process cached it
	 * when it started: the options cache is dropped first, so a record another
	 * request wrote meanwhile (a settings save while an answer was on its way)
	 * is seen. One query, in the hourly run only.
	 *
	 * @return mixed
	 */
	private static function fresh() {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		return get_option( self::OPTION );
	}

	/**
	 * The container address, or '' when there is nothing to ask for the
	 * shield: no custom loader, a placement under which no page prints the
	 * loader ("Disable", "Plugin"), no container address of the site's own,
	 * no HTTPS, or no path prefix for the shim to scope to. Options only, no
	 * transient, so the scheduling on init costs no query.
	 *
	 * @return string
	 */
	public static function target() {
		if ( ! GTM_Server_Side_Helpers::has_gtm_container_identifier() ) {
			return '';
		}

		if ( ! GTM_Server_Side_Helpers::is_enable_placement_code() && ! GTM_Server_Side_Helpers::is_enable_placement_gtm_consent() ) {
			return '';
		}

		if ( '' === GTM_Server_Side_Helpers::normalize_container_url( GTM_Server_Side_Helpers::get_raw_gtm_container_url() ) ) {
			return '';
		}

		$url = GTM_Server_Side_Helpers::get_gtm_container_url();
		if ( 0 !== strpos( $url, 'https://' ) ) {
			return '';
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === rtrim( $path, '/' ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * The address the check asks and a page printed for the loader asks for:
	 * <container URL>/<identifier>.js?<the p1 query>. '' when there is
	 * nothing to ask, or when a value the query would carry is one the
	 * worker's parser refuses (the contract above).
	 *
	 * @return string
	 */
	public static function address() {
		$url = self::target();
		if ( '' === $url || ! self::values_well_formed() ) {
			return '';
		}

		return $url . '/' . GTM_Server_Side_Helpers::get_gtm_container_identifier() . '.js?' . GTM_Server_Side_Helpers::get_gtm_container_id( true );
	}

	/**
	 * Would the worker's parser accept the values the p1 query carries? The
	 * same four limits as decodeLoaderValues in the workers, on the blob the
	 * query helper writes.
	 *
	 * @return bool
	 */
	public static function values_well_formed() {
		$id	 = GTM_Server_Side_Helpers::get_raw_gtm_container_id();
		$ga	 = GTM_Server_Side_Helpers::get_ga4_fallback_id();
		$suf = GTM_Server_Side_Helpers::is_enable_data_layer_custom_event_name() ? GTM_SERVER_SIDE_DATA_LAYER_CUSTOM_EVENT_NAME : '';

		if ( '' !== $id && 1 !== preg_match( self::ID_RE, $id ) ) {
			return false;
		}
		if ( 1 === preg_match( self::ID_CUT_RE, $id ) ) {
			return false;
		}
		if ( '' !== $ga && 1 !== preg_match( self::GA4_ID_RE, $ga ) ) {
			return false;
		}
		if ( 1 !== preg_match( self::SUFFIX_RE, $suf ) ) {
			return false;
		}

		$blob = 'id=' . $id . '&ga=' . $ga . '&suf=' . $suf . '&p=1';

		return strlen( $blob ) <= self::BLOB_MAX && 1 === preg_match( self::BLOB_RE, $blob );
	}

	/**
	 * Keep the hourly event in step with the settings: scheduled while a page
	 * prints a custom loader that can be asked for the shield, cleared when
	 * none does. The cron option is autoloaded, so this costs no query on an
	 * ordinary page.
	 *
	 * @return void
	 */
	public function schedule() {
		$next = wp_next_scheduled( self::HOOK );
		if ( '' === self::target() ) {
			if ( $next ) {
				wp_clear_scheduled_hook( self::HOOK );
			}
			return;
		}

		if ( ! $next ) {
			wp_schedule_event( time() + self::DELAY, 'hourly', self::HOOK );
		}
	}

	/**
	 * A setting the query or the address depends on changed: the old answer
	 * is worthless. Record a "no" that has already run out (the option is
	 * never deleted; the generation counted up, so an answer still on its way
	 * is not stored), purge the pages printed for the loader from the caches,
	 * then ask again at once: the run is scheduled after the purge, so the
	 * answer it stores is never older than the purge.
	 *
	 * @return void
	 */
	public function reset() {
		update_option( self::OPTION, self::dropped( get_option( self::OPTION ) ), true );
		self::purge_if_owed();
		wp_clear_scheduled_hook( self::HOOK );
		if ( '' !== self::target() ) {
			wp_schedule_event( time(), 'hourly', self::HOOK );
		}
	}

	/**
	 * A plugin version change (GTM_Server_Side_Plugin_Upgrade) and an
	 * activation (GTM_Server_Side_Plugin_Activate): the record is dropped, a
	 * "yes" in it remembered for the purge that the next "no", reset or
	 * deactivation runs; the hourly run that follows asks again.
	 *
	 * @return void
	 */
	public static function forget() {
		update_option( self::OPTION, self::dropped( get_option( self::OPTION ) ), true );
	}

	/**
	 * Deactivation (GTM_Server_Side_Plugin_Deactivate): the record dropped,
	 * the event cleared, the caches purged when pages printed for the loader
	 * may sit in one.
	 *
	 * @return void
	 */
	public static function retire() {
		update_option( self::OPTION, self::dropped( get_option( self::OPTION ) ), true );
		wp_clear_scheduled_hook( self::HOOK );
		self::purge_if_owed();
	}

	/**
	 * A "no" that has already run out, one generation up, remembering whether
	 * pages printed for the loader may sit in a cache: what a reset, an update,
	 * an activation and a deactivation write.
	 *
	 * @param  mixed $record The option's value before.
	 * @return array
	 */
	private static function dropped( $record ) {
		return array(
			'v'		=> 'no',
			'until' => 0,
			'fp'	=> '',
			'g'		=> self::generation( $record ) + 1,
			'y'		=> self::may_cache( $record ) ? 1 : 0,
		);
	}

	/**
	 * The hourly run: one HEAD of the loader address, three seconds at most,
	 * no redirects, no cookies, the answer capped at 16 KB, through the safe
	 * variant (an address that resolves to a local or private host is refused
	 * there, and reads as no answer). The address asked is the one a page
	 * printed for the loader asks for.
	 *
	 * @return void
	 */
	public function run() {
		$g		 = self::generation( get_option( self::OPTION ) );
		$address = self::address();

		// Nothing to ask for the shield: a "no", since the settings decide it,
		// not the network.
		if ( '' === $address ) {
			self::store( 'no', '', $g );
			return;
		}

		$response = wp_safe_remote_head(
			$address,
			array(
				'timeout'			  => 3,
				'redirection'		  => 0,
				'sslverify'			  => true,
				'cookies'			  => array(),
				'limit_response_size' => 16384,
			)
		);

		// No answer at all proves nothing: the record runs out on its own time.
		if ( is_wp_error( $response ) ) {
			return;
		}

		// Nor does a 5xx or a 429 (Cloudflare's Error 1027 of a spent daily
		// allowance among them): the edge or a server in trouble, not a worker
		// without the shield, and the loader fails for the visitor whatever the
		// page printed. The record runs out on its own time, as with no answer
		// (the owner's decision of 9 Nov 2026).
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status >= 500 || 429 === $status ) {
			return;
		}

		// The address may have changed while the answer was on its way (a
		// settings save, a reset): an answer for another address than the
		// one a page would ask now is not stored; the run the reset
		// scheduled asks the new one.
		if ( $address !== self::address() ) {
			return;
		}

		self::store( self::says_yes( $response ) ? 'yes' : 'no', $address, $g );
	}

	/**
	 * Does the answer say the shield is served? 204 and X-Synapse-Shield:
	 * p1;<worker version>;<hash>, the hash being this plugin's own
	 * (pieces_hash()); anything else is no. The worker's version is not
	 * read: the pieces decide, whichever worker carries them.
	 *
	 * @param  array|WP_Error $response The wp_safe_remote_head() result.
	 * @return bool
	 */
	public static function says_yes( $response ) {
		if ( is_wp_error( $response ) || 204 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$header = wp_remote_retrieve_header( $response, self::HEADER );
		if ( is_array( $header ) ) {
			$header = count( $header ) ? $header[0] : '';
		}

		$parts = explode( ';', trim( (string) $header ) );
		if ( 'p1' !== $parts[0] || ! isset( $parts[2] ) ) {
			return false;
		}
		$hash = self::pieces_hash();

		return '' !== $hash && $parts[2] === $hash;
	}

	/**
	 * The hash of the four pieces of the protection as this plugin's own
	 * tracking-code file holds them: the body of each piece method, between
	 * its SHIELD PIECE markers, in file order, md5 cut to 12 hex digits. The
	 * worker composes its shield from a copy of exactly this code
	 * (shield-build.mjs writes the same hash into it), so a worker built from
	 * another version of the pieces says another hash and gets a "no": the
	 * page prints inline and never relies on a shield that is not its own.
	 * Read in the hourly run only, never on a page. '' when the file or its
	 * four pairs of markers are not found.
	 *
	 * @return string
	 */
	public static function pieces_hash() {
		$src = file_get_contents( GTM_SERVER_SIDE_PATH . self::PIECES_FILE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_string( $src ) ) {
			return '';
		}

		$text = '';
		$at   = 0;
		foreach ( array( 1, 2, 3, 4 ) as $piece ) {
			$a = strpos( $src, self::PIECE_BEGIN, $at );
			$b = false === $a ? false : strpos( $src, self::PIECE_END, $a );
			if ( false === $b ) {
				return '';
			}
			$text .= substr( $src, $a, $b - $a );
			$at    = $b + strlen( self::PIECE_END );
		}
		if ( false !== strpos( $src, self::PIECE_BEGIN, $at ) ) {
			return '';
		}

		return substr( md5( $text ), 0, 12 );
	}

	/**
	 * Purge the page caches this plugin knows: WP Rocket, WP Super Cache, W3
	 * Total Cache and LiteSpeed Cache. Each one only where it is installed; a
	 * missing one is skipped without a sound. The object cache is flushed
	 * only where none of the four is found: a page cache this plugin does not
	 * know by name may keep its pages there, but a flush drops every cached
	 * object and transient of the site (and of its neighbours on a shared
	 * store), so it is the last resort, never an extra (the owner's decision
	 * of 9 Nov 2026).
	 *
	 * @return void
	 */
	public static function purge_page_caches() {
		$known = false;
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$known = true;
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
			$known = true;
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
			$known = true;
		}
		if ( has_action( 'litespeed_purge_all' ) ) {
			$known = true;
		}
		do_action( 'litespeed_purge_all' );
		if ( ! $known && function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		// A cache this plugin does not know (a host cache, Cloudflare APO, a
		// Cache Rule) is the owner's: a small plugin of theirs can purge it here.
		do_action( 'synapse_ct_purge_page_caches' );
	}

	/**
	 * Record the answer with its expiry, a "yes" bound to the address it was
	 * given for, under the generation the run asked under: a record dropped
	 * while the answer was on its way (a reset, a version change, a
	 * deactivation; for this address or another) is read fresh from the
	 * database and stands, and the run the reset scheduled asks again. A "no"
	 * after pages were printed for the loader purges the page caches. A "yes"
	 * after anything but a "yes" purges them too, once the record is written
	 * (the owner's decision of 9 Nov 2026): the pages cached until then are
	 * the long ones, and the ones rendered after the purge are short.
	 *
	 * @param  string $v	   'yes' or 'no'.
	 * @param  string $address The address asked ('' for a "no" without one).
	 * @param  int	  $g	   The generation of the record when the run began.
	 * @return void
	 */
	private static function store( $v, $address, $g ) {
		$record = self::fresh();
		if ( self::generation( $record ) !== $g ) {
			return;
		}
		$turn = 'yes' === $v && ! self::said_yes( $record );
		update_option(
			self::OPTION,
			array(
				'v'		=> $v,
				'until' => time() + ( 'yes' === $v ? self::TTL_YES : self::TTL_NO ),
				'fp'	=> 'yes' === $v ? md5( $address ) : '',
				'g'		=> $g,
				'y'		=> ( 'yes' === $v || self::may_cache( $record ) ) ? 1 : 0,
			),
			true
		);
		if ( 'yes' !== $v ) {
			self::purge_if_owed();
		} elseif ( $turn ) {
			self::purge_page_caches();
		}
	}

	/**
	 * Purge the page caches when pages printed for the loader may sit in one
	 * and the record no longer says "yes", and note the purge in the record.
	 * The record is read again, so what is purged for is what the option
	 * holds now. The purge takes its seconds; the note goes on the record the
	 * purge was decided for, read fresh from the database afterwards: a
	 * record written meanwhile (a settings save, a deactivation, a "yes" of a
	 * run) is left as it is, with its own note of what it owes.
	 *
	 * @return void
	 */
	private static function purge_if_owed() {
		$record = get_option( self::OPTION );
		if ( self::said_yes( $record ) || ! self::may_cache( $record ) ) {
			return;
		}
		self::purge_page_caches();
		$now = self::fresh();
		if ( ! is_array( $now ) || self::generation( $now ) !== self::generation( $record ) || self::said_yes( $now ) ) {
			return;
		}
		$now['y'] = 0;
		update_option( self::OPTION, $now, true );
	}
}
