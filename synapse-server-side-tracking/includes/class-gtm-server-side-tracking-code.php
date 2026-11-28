<?php
/**
 * The public-facing functionality of the plugin.
 *
 * @package    GTM_Server_Side
 * @subpackage GTM_Server_Side/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tracking by code.
 */
class GTM_Server_Side_Tracking_Code {
	use GTM_Server_Side_Singleton;

	/**
	 * Init.
	 *
	 * @return void
	 */
	public function init() {
		if (
			! GTM_Server_Side_Helpers::is_enable_placement_code() &&
			! GTM_Server_Side_Helpers::is_enable_placement_gtm_consent()
		) {
			return;
		}

		add_action( 'wp_head', array( $this, 'wp_head' ) );
	}

	/**
	 * Add GTM Head.
	 *
	 * @return void
	 */
	public function wp_head() {
		if ( is_user_logged_in() && GTM_Server_Side_Helpers::is_enable_gtm_exclude_roles() ) {
			$current_user  = wp_get_current_user();
			$exclude_roles = GTM_Server_Side_Helpers::get_gtm_exclude_list_roles();

			if ( array_intersect( $exclude_roles, $current_user->roles ) ) {
				return;
			}
		}

		if ( GTM_Server_Side_Helpers::is_enable_placement_gtm_consent() ) {
			$this->print_gtm_consent_loader();
		}

		if ( GTM_Server_Side_Helpers::has_gtm_container_identifier() ) {
			$this->print_synapse_gtm_code();
			return;
		}

		$this->print_default_gtm_code();
	}

	/**
	 * Print default GTM Code.
	 *
	 * @return void
	 */
	private function print_default_gtm_code() {
		// The container id is a JSON string literal, like in the custom loader
		// (see js_string()). Here it is the raw "GTM-XXXX" id, which has no "&"
		// for esc_js() to break, but the two snippets now encode it one way.
		$id_js = self::js_string( GTM_Server_Side_Helpers::get_gtm_container_id() );

		// phpcs:ignore Squiz.Strings.DoubleQuoteUsage.NotRequired
		echo "\n<script" . $this->print_tag_script_attrs() /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ . ">" . $this->get_keepalive_helper_js() /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ . "(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='" . esc_js( GTM_Server_Side_Helpers::get_gtm_container_url() ) . '/' . esc_js( GTM_Server_Side_Helpers::get_gtm_container_identifier() ) . ".js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer'," . $id_js /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ . ");</script>\n";
	}

	/**
	 * Print custom-loader GTM Code.
	 *
	 * @return void
	 */
	private function print_synapse_gtm_code() {
		// The blocker probe comes first: it takes its fetch reference before any
		// wrapper exists, so its two requests leave the browser exactly as
		// written, which is what a content blocker has to see. The enhanced
		// ad-blocker shim follows, as the next statement INSIDE the loader's own
		// <script>, so a single first-party script both installs the network-API
		// wrappers and injects the container - in that order. Wrappers are in
		// place before the container script runs, so every hit it later sends is
		// rewritten. It has no setting: it ships whenever the container URL has a
		// path prefix to scope it to, and the string is empty (only the loader is
		// printed) when it has not; the probe ships under the same condition,
		// since only a rewritten hit can carry its verdict. The keepalive helper
		// follows the shim: it calls window.fetch at send time, so its hits go
		// through the shim and leave encoded like any other. The GA4 recovery
		// watchdog (when a GA4 Measurement ID is set) is emitted after the shim
		// on purpose: its observers then wrap on top of the shim's, so they see
		// the plain (pre-encoding) hit URLs, while its own recovery hit - sent
		// via a fetch reference captured after the shim installed - still gets
		// encoded. Once the hourly check (GTM_Server_Side_Shield_Check) has seen
		// the container address serve the shield (the probe, the shim, the
		// keepalive helper and the GA4 watchdog composed into the loader script
		// at the edge), the page prints the loader line alone and the loader
		// brings the rest, the probe as well. Any doubt (no record, an expired
		// one, a "no") prints everything inline, with the loader address of an
		// inline page. Decided once per page: the loader line below asks for the
		// shield exactly when the page relies on it.
		$shim   = $this->get_enhanced_adblocker_shim_js();
		$served = '' !== $shim && GTM_Server_Side_Shield_Check::is_served();
		$head   = $served ? '' : $this->get_blocker_probe_js() . $shim . $this->get_keepalive_helper_js() . $this->get_ga4_fallback_js();

		// The container loader closes the script and runs right after the
		// wrappers. Nothing is loaded before it and nothing gates it.
		$loader = $this->get_gtm_loader_js( $served );

		// phpcs:ignore Squiz.Strings.DoubleQuoteUsage.NotRequired
		echo "\n<script" . $this->print_tag_script_attrs() /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ . ">" . $head /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ . $loader /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ . "</script>\n";
	}

	/**
	 * Build the GTM container loader IIFE (the closing statement of the head
	 * script).
	 *
	 * With the custom loader the container id is the obfuscated query
	 * "<key>=<base64>&<end>". It is printed as a JSON string literal (see
	 * js_string()), not through esc_js(), which is meant for HTML attributes
	 * and would turn that "&" into "&amp;" and corrupt every container request.
	 *
	 * @param  bool $shield The page is printed for the loader, so its
	 *                      query asks the edge for the shield.
	 * @return string
	 */
	private function get_gtm_loader_js( $shield = false ) {
		$id_js = self::js_string( GTM_Server_Side_Helpers::get_gtm_container_id( $shield ) );

		// The loader line, printed on every page: a loader that fails loads
		// nothing more.
		// phpcs:ignore Squiz.Strings.DoubleQuoteUsage.NotRequired
		return "(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s);j.async=true;j.src=\"" . esc_js( GTM_Server_Side_Helpers::get_gtm_container_url() ) . '/' . esc_js( GTM_Server_Side_Helpers::get_gtm_container_identifier() ) . ".js?\"+i;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer'," . $id_js . ');';
	}

	/**
	 * Build the keepalive helper the Synapse Tag sends through.
	 *
	 * GTM templates can only send image pixels, and an image request started
	 * while the page is being left (an add_to_cart pushed on a form submit, a
	 * click on a link) is usually cancelled. This helper gives the tag a fetch
	 * with keepalive, which the browser completes after the page is gone. The
	 * tag calls window.__synSend(url, retryUrl) only when __synSendReady is
	 * true; the helper returns true once the request is handed to fetch, and if
	 * that fetch fails in transit it loads retryUrl (the tag's disguised retry,
	 * marked _synr=1) as an image. Without fetch it returns false and the tag
	 * sends its own pixel. Fails open: any error leaves the page untouched.
	 *
	 * @return string Bare IIFE.
	 */
	private function get_keepalive_helper_js() {
		// ---- SHIELD PIECE BEGIN ----
		return '(function(w){try{if(w.__synSendReady)return;w.__synSend=function(u,r){try{if("function"!==typeof w.fetch)return false;var p=w.fetch(u,{keepalive:true,credentials:"include"});if(p&&"function"===typeof p.catch)p.catch(function(){try{(new w.Image(1,1)).src=r}catch(x){}});return true}catch(x){return false}};w.__synSendReady=true}catch(x){}})(window);';
		// ---- SHIELD PIECE END ----
	}

	/**
	 * A PHP string as a JavaScript string literal, safe inside an inline script.
	 *
	 * JSON is a valid JS string literal by definition. JSON_HEX_TAG writes "<"
	 * and ">" as unicode escapes, so no value can close the script element,
	 * and JSON_UNESCAPED_SLASHES leaves "/" alone, so an ordinary id prints
	 * byte for byte as before, in double quotes instead of single ones. A value
	 * JSON cannot encode (invalid UTF-8) becomes "", which is also what esc_js()
	 * made of it, so the snippet never turns into a syntax error.
	 *
	 * @param  string $value Raw value.
	 * @return string Double-quoted JavaScript string literal.
	 */
	private static function js_string( $value ) {
		$json = wp_json_encode( (string) $value, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG );

		return is_string( $json ) ? $json : '""';
	}

	/**
	 * Encode a Google id so it is not readable in the page source.
	 *
	 * Standard base64 is deliberate, not incidental: its alphabet contains no
	 * "-", while every Google id (G-, GTM-, AW-, DC-, UA-) does. That single
	 * property lets the watchdog tell an encoded value from a plain one with no
	 * flag and no ambiguity (see dec() in get_ga4_fallback_js()). Changing to
	 * base64url (which uses "-") would break that and must not be done.
	 *
	 * This is camouflage against automated scanning of the HTML, nothing more:
	 * the watchdog decodes the value at runtime and every request that leaves
	 * the browser carries the plain id, exactly as before.
	 *
	 * An empty id stays empty: the watchdog reads "" as "feature off", and
	 * base64 of "" is "" anyway, but saying so explicitly keeps it readable.
	 *
	 * @param string $id Raw id, '' when the feature is off.
	 * @return string
	 */
	private static function encode_id( $id ) {
		$id = (string) $id;

		return '' === $id ? '' : base64_encode( $id );
	}

	/**
	 * Print GTM Consent Loader Code.
	 *
	 * The gate runs where it is printed, before the parked script below it
	 * exists in the document. A consent already granted when the gate runs (a
	 * consent tool earlier in the head that pushes a stored choice) counts as
	 * handled only once the gate has released the parked script; otherwise the
	 * gate tries again when the document is parsed and on every later consent
	 * push.
	 *
	 * @return void
	 */
	private function print_gtm_consent_loader() {
		echo '<script>!function(){"use strict";for(var t={window:window,gtmVariable:"dataLayer",onConsentGranted:function(){var t,e,n;return t="custom-loader",!!((t=document.getElementById(t))&&"text/plain"===t.type&&(t.type="text/javascript",n=t.cloneNode(!0),null!=(e=t.parentNode))&&e.replaceChild(n,t))}},e=t.window,n=t.gtmVariable,a=t.onConsentGranted,r=((t=e)[n]||(t[n]=[]),!1),s=!1,o=t[n],d=function(){r||(r=!!a())||s||(s=!0,document.addEventListener("DOMContentLoaded",function(){r||(r=!!a())}))},i=function(t){return!(!t||"consent"!==t[0]||-1===["default","update"].indexOf(t[1])||!(t=t[2])||"object"!=typeof t||"granted"!==t.ad_storage&&"granted"!==t.analytics_storage)},u=o.push,l=(o.push=function(){for(var t=[],e=0;e<arguments.length;e++)t[e]=arguments[e];var n=u.apply(o,t);return i(t[0])&&d(),n},t[n]),c=l.length-1;0<=c;c--){var p=l[c];if(i(p))return d()}}();</script>';
	}

	/**
	 * Build the content-blocker probe as a JavaScript string.
	 *
	 * With the disguised first-party transport nothing of ours gets blocked, so
	 * the server cannot tell a visitor with uBlock Origin from one without: both
	 * arrive the same way. This probe asks the browser directly, before GTM
	 * loads. It sends two tiny same-origin requests with the fetch reference it
	 * takes before any wrapper exists:
	 *
	 *   the bait    <prefix>/analytics/pageview/__utm.gif/ads?id=synapse&/collect/pv?r=...
	 *   the control <prefix>/_ok?r=...
	 *
	 * The bait carries four patterns from the generic (any-domain, any-type)
	 * rules of the lists content blockers ship, so that EVERY blocker with
	 * standard lists refuses it: "/ads?id=" from EasyList (the ad list of
	 * AdBlock, Adblock Plus, AdGuard, uBlock Origin, Brave), "/analytics/pageview"
	 * and "/__utm.gif" from EasyPrivacy (uBlock Origin, Brave, AdGuard's
	 * EasyPrivacy option) and "/collect/pv?" from AdGuard Tracking Protection.
	 * Rules match the whole address as a substring, so the patterns may sit in
	 * the path or in the query. An ad-only blocker such as AdBlock with its
	 * default lists still stops the Meta pixel script and Google Ads pixels,
	 * which the tagging server sends for the site instead, so a visitor it
	 * refuses is a rescued visitor. Blocked, fetch rejects at once. The control
	 * has no such pattern; if it fails too, the network is down, not a blocker,
	 * and the verdict is withdrawn. Any response at all (the edge worker answers
	 * both with 204, a server that does not know them with 400) means "not
	 * blocked". Brave's default Shields block third-party requests only, so the
	 * bait passes there; the server already counts Brave by its client hints,
	 * and the two never overlap. Firefox's own tracking protection blocks by
	 * domain and is not seen: the count stays a floor.
	 *
	 * The verdict is window.__synBlk: 1 while a blocker is proven, 0 otherwise.
	 * The shim reads it on every hit it rewrites and stamps "_synr=3" into the
	 * encoded part (see get_enhanced_adblocker_shim_js). Nothing is stored, no
	 * cookie is set and no identifier is sent: the probes carry no cookies
	 * (credentials "omit") and are never cached. Each page asks again. Fails
	 * open: any error leaves the verdict at 0.
	 *
	 * A cached container can fire its first tags before the bait's answer is
	 * processed, and those hits would leave unmarked. The probe therefore also
	 * publishes when it started (__synBlkT), a promise that settles with the
	 * bait (__synBlkP) and a flag set in the bait's own handlers (__synBlkS,
	 * also set when the control fails, since no verdict can follow then), so the
	 * shim can hold its first hits until the answer is in. Both requests go at
	 * high priority: a verdict that hits wait on must not queue behind the
	 * page's stylesheets.
	 *
	 * Ships under the same condition as the shim - a container URL with a path
	 * - because only a rewritten hit can carry the verdict.
	 *
	 * It also sets window.__synPrb = 1. The protection the edge composes into
	 * the loader script (the shield) stands down entirely when it finds
	 * __synBlk set without __synPrb, which is what a page of an earlier version
	 * prints; with __synPrb it only skips its own copy of this probe.
	 *
	 * @return string Bare IIFE, or '' when there is no path prefix.
	 */
	private function get_blocker_probe_js() {
		// ---- SHIELD PIECE BEGIN ----
		$prefix = wp_parse_url( GTM_Server_Side_Helpers::get_gtm_container_url(), PHP_URL_PATH );
		$prefix = is_string( $prefix ) ? rtrim( $prefix, '/' ) : '';
		if ( '' === $prefix ) {
			return '';
		}

		$prefix_js = wp_json_encode( $prefix );

		return '(function(w){try{if(w.__synBlk!==void 0)return;w.__synBlk=0;w.__synPrb=1;w.__synBlkT=Date.now();var P=' . $prefix_js . ',O=w.location&&w.location.origin,F=w.fetch;if(!O||"function"!==typeof F)return;var f=F.bind(w),o={cache:"no-store",credentials:"omit",mode:"same-origin",priority:"high"},n=String(Math.random()).slice(2),c=0;f(O+P+"/_ok?r="+n,o).then(function(){c=1},function(){c=-1;w.__synBlk=0;w.__synBlkS=1});w.__synBlkP=f(O+P+"/analytics/pageview/__utm.gif/ads?id=synapse&/collect/pv?r="+n,o).then(function(){w.__synBlk=0;w.__synBlkS=1},function(){if(c!==-1)w.__synBlk=1;w.__synBlkS=1})}catch(x){}})(window);';
		// ---- SHIELD PIECE END ----
	}

	/**
	 * Build the enhanced ad-blocker protection shim as a JavaScript string.
	 *
	 * Wraps navigator.sendBeacon / fetch / XMLHttpRequest so every hit the GTM
	 * container sends to the custom-loader transport path is rewritten from the
	 * ad-block-recognisable "<prefix>/g/collect?v=2&tid=..." shape into
	 * "<prefix>/<random>?ei=<base64url(realPath+query)>". The edge worker
	 * decodes it back before the server container, which still previews the
	 * plain request. Ad blockers match the visible URL, never the body, so
	 * hiding the path+query defeats the filter lists. Fails open: any error
	 * leaves the original request untouched so tracking never breaks.
	 *
	 * Returned as a bare IIFE (no <script> wrapper) so the caller can emit it as
	 * the opening statement of the loader's own script tag - one first-party
	 * script installs the wrappers and then loads the container. Returns '' when
	 * the transport has no distinctive path prefix to scope the rewrite to (a
	 * bare origin), so nothing unrelated is touched. There is no setting for it:
	 * wherever it can run (the custom loader is active, which is the only
	 * caller, and the container URL has a path) it does.
	 *
	 * The "ei" param key must stay in sync with EAB_PARAM in the edge worker.
	 *
	 * When the blocker probe (get_blocker_probe_js) has found a content blocker
	 * on the page, every rewritten hit also carries "_synr=3" inside the
	 * encoded part, so it reaches the tagging server as a plain query parameter
	 * and the server logs count the hit as rescued from a blocker. The Synapse
	 * Client treats "_synr" as transport, never as event data. A hit in the
	 * plain fallback form below carries no mark: it left untouched.
	 *
	 * The hold. A fetch sent with keepalive before the probe has its answer is
	 * held, and rewritten only when it leaves, so its mark follows the verdict.
	 * The caller gets a Promise that settles with the real fetch. Every held
	 * request leaves exactly once, in call order, at the FIRST of: the bait
	 * settling, 300 ms, pagehide, or the page turning hidden. The first of
	 * these closes the hold for good before anything is sent, so a fetch made
	 * by a later pagehide listener (the GA4 watchdog, Google's own tag) goes
	 * straight out; all four are registered here, before any other script can
	 * add its own. Nothing is held while the page is hidden, and never a
	 * request without keepalive (flushed at unload it would be cancelled, which
	 * would be worse than an unmarked hit). sendBeacon and XMLHttpRequest are
	 * never held: they use the verdict of the moment. One failing item cannot
	 * stop the rest: it is sent in its original form, and its caller's promise
	 * always settles.
	 *
	 * A worker that does not decode "ei" answers the encoded request with a 4xx
	 * (404 where the route is missing, 400 where an unknown path is passed on
	 * to the server container). A shim that only rewrote and forwarded would
	 * lose every such hit silently, so the shim reads the response where a
	 * response exists - fetch and XMLHttpRequest, not sendBeacon - and on the
	 * first 4xx it resends that one request in its original plain form, stops
	 * encoding for the rest of the page so nothing else is doubled, and raises
	 * one "ei" signal carrying the status. A 5xx raises the signal but is
	 * neither resent nor treated as a broken worker, because a server that
	 * failed after accepting the hit would count it twice. A network error
	 * changes nothing: that is the ad-blocker case the encoding exists for, and
	 * falling back there would hand the hit to the blocker.
	 *
	 * A second copy on the same page installs nothing (__synShim): the page can
	 * carry this copy and the loader can bring the shield, which is built from
	 * this same source. Where the shim runs as part of that shield
	 * (window.__synShd, set by the edge), it also rewrites the src of image and
	 * script elements under the path, through the src setters and setAttribute,
	 * except the loader itself, /gtm.js, /ns.html, /healthy, /gtm/* and the two
	 * probes; scripts carry no "_synr=3". Only a worker that serves the shield
	 * is known to decode "ei" before it decides what a request is, so the copy
	 * printed in the page never touches elements. The original address of a
	 * rewritten element is kept in window.__synSrc (a WeakMap) for the GA4
	 * watchdog. A disguised script keeps one name from one view to the next (a
	 * hash of its own address, h()), so the browser can keep the Google tag
	 * script as Google's headers allow instead of fetching it on every page;
	 * hits and images keep their random names.
	 *
	 * @return string
	 */
	private function get_enhanced_adblocker_shim_js() {
		// ---- SHIELD PIECE BEGIN ----
		$prefix = wp_parse_url( GTM_Server_Side_Helpers::get_gtm_container_url(), PHP_URL_PATH );
		$prefix = is_string( $prefix ) ? rtrim( $prefix, '/' ) : '';
		if ( '' === $prefix ) {
			return '';
		}

		$prefix_js = wp_json_encode( $prefix );
		// 2.3.0: the loader's own name, so the element hooks below leave it alone.
		$loader_js = wp_json_encode( GTM_Server_Side_Helpers::get_raw_gtm_container_identifier() . '.js' );

		return '(function(w){try{if(w.__synShim)return;w.__synShim=1;var P=' . $prefix_js . ',K="ei",LN=' . $loader_js . ',O=w.location&&w.location.origin;if(!O)return;var off=false,s5=false;function b(s){return w.btoa(unescape(encodeURIComponent(s))).replace(/\+/g,"-").replace(/\//g,"_").replace(/=+$/,"")}function r(){for(var c="abcdefghijklmnopqrstuvwxyz0123456789",s="",n=1+(Math.random()*8|0),i=0;i<n;i++)s+=c.charAt(Math.random()*c.length|0);return s}function h(s){for(var x=5381,i=0;i<s.length;i++)x=((x<<5)+x+s.charCodeAt(i))|0;return(x>>>0).toString(36)}function enc(u,k){try{if(off)return null;var a=new URL(u,w.location.href);if(a.origin!==O)return null;if(a.pathname.slice(0,P.length+1)!==P+"/")return null;var rel=a.pathname.slice(P.length);if(k){if(rel.slice(-LN.length)===LN||rel==="/gtm.js"||rel==="/ns.html"||rel==="/healthy"||rel==="/_ok"||rel==="/analytics/pageview/__utm.gif/ads"||rel.slice(0,5)==="/gtm/")return null}else if(rel==="/gtm.js"||rel==="/gtag/js"||/\.js$/.test(rel)||rel.slice(0,5)==="/gtm/")return null;if(a.searchParams.has(K))return null;var q=rel+a.search;if(w.__synBlk===1&&2!==k)q+=(a.search?"&":"?")+"_synr=3";return O+P+"/"+(2===k?h(q):r())+"?"+K+"="+b(q)}catch(x){return null}}function e(u){return enc(u,0)}function sig(st){try{if("function"===typeof w.__synSig){w.__synSig("ei",String(st));return}if(w.Image){var px=new w.Image(1,1);px.src=O+P+"/_sg?k=ei&n="+encodeURIComponent(String(st))+"&_syng=1"}}catch(x){}}function trip(st){if(off)return;off=true;try{w.__synEiOff=1}catch(x){}sig(st)}function five(st){if(s5)return;s5=true;sig(st)}function judge(st,again){try{if(st>=400&&st<500){trip(st);if(again)again()}else if(st>=500)five(st)}catch(x){}}var nav=w.navigator,Q=[],fl=0,bp=w.__synBlkP,doc=w.document;function fls(){if(fl)return;fl=1;var q=Q;Q=[];for(var k=0;k<q.length;k++){var t=q[k];try{t.res(t.go())}catch(x){try{t.res(of.apply(w,t.args))}catch(y){t.rej(y)}}}}function hd(ka){return!fl&&!!ka&&!w.__synBlkS&&!(doc&&"hidden"===doc.visibilityState)&&Date.now()-(w.__synBlkT||0)<300}try{if(bp&&"function"===typeof bp.then){bp.then(fls,fls);w.setTimeout(fls,300);if(w.addEventListener)w.addEventListener("pagehide",fls);if(doc&&doc.addEventListener)doc.addEventListener("visibilitychange",function(){if("hidden"===doc.visibilityState)fls()})}else fl=1}catch(x){fl=1}if(nav&&nav.sendBeacon){var sb=nav.sendBeacon.bind(nav);nav.sendBeacon=function(u,d){var n=e(u);return n?sb(n,d):sb(u,d)}}if(w.fetch){var of=w.fetch;w.fetch=function(i,o){var args=arguments;try{var n=null,c=null,str=(typeof i==="string"||i instanceof URL);if(str){n=e(i)}else if(i&&i.url){n=e(i.url);if(n){try{c=i.clone?i.clone():null}catch(x){c=null}}}if(n){var go=function(){var m=e(str?i:i.url);if(!m)return of.apply(w,args);var p=str?of.call(w,m,o):of.call(w,new Request(m,i),o);try{if(p&&typeof p.then==="function")p.then(function(res){judge(res&&res.status,function(){try{if(c)of.call(w,c,o);else of.apply(w,args)}catch(x){}})},function(){})}catch(x){}return p};if(hd(str?(o&&o.keepalive):(i.keepalive||(o&&o.keepalive))))return new Promise(function(res,rej){Q.push({args:args,res:res,rej:rej,go:go})});return go()}}catch(x){}return of.apply(w,args)}}var X=w.XMLHttpRequest;if(X&&X.prototype&&X.prototype.open){var oo=X.prototype.open,os=X.prototype.send,oh=X.prototype.setRequestHeader;X.prototype.open=function(){try{var n=e(arguments[1]);if(n){this.__synO=[arguments[0],String(arguments[1])];this.__synH=[];arguments[1]=n}}catch(x){}return oo.apply(this,arguments)};if(oh)X.prototype.setRequestHeader=function(k,v){try{if(this.__synH)this.__synH.push([k,v])}catch(x){}return oh.apply(this,arguments)};if(os)X.prototype.send=function(bd){try{var self=this;if(self.__synO&&self.addEventListener)self.addEventListener("load",function(){judge(self.status,function(){try{var x2=new X();x2.open(self.__synO[0],self.__synO[1],true);for(var j=0;j<self.__synH.length;j++)x2.setRequestHeader(self.__synH[j][0],self.__synH[j][1]);x2.send(bd)}catch(x){}})})}catch(x){}return os.apply(this,arguments)}}var M=w.__synShd&&w.WeakMap?new w.WeakMap():null;if(M){w.__synSrc=M;var el=function(t,v,k){var m=enc(String(v),k);if(m)M.set(t,String(v));else M.delete(t);return m},hk=function(C,k){try{var d=C&&C.prototype&&Object.getOwnPropertyDescriptor(C.prototype,"src");if(!d||!d.set||!d.get)return;Object.defineProperty(C.prototype,"src",{configurable:true,enumerable:!!d.enumerable,get:d.get,set:function(v){var m=null;try{m=el(this,v,k)}catch(x){}d.set.call(this,m||v)}})}catch(x){}};hk(w.HTMLImageElement,1);hk(w.HTMLScriptElement,2);var E=w.Element;if(E&&E.prototype&&E.prototype.setAttribute){var sa=E.prototype.setAttribute;E.prototype.setAttribute=function(n,v){try{if("src"===String(n).toLowerCase()){var t=String(this.tagName||"").toUpperCase(),k="IMG"===t?1:"SCRIPT"===t?2:0;if(k){var m=el(this,v,k);if(m)return sa.call(this,n,m)}}}catch(x){}return sa.apply(this,arguments)}}}}catch(x){}})(window);';
		// ---- SHIELD PIECE END ----
	}

	/**
	 * Build the GA4 measurement recovery watchdog as a JavaScript string.
	 *
	 * Some browser privacy modes (most notably iOS Safari Private Browsing)
	 * let the web GTM container run but silently prevent its Google tag from
	 * ever dispatching a /g/collect hit, while plain first-party fetches keep
	 * working. This watchdog observes the page's fetch/sendBeacon/XHR for any
	 * same-origin "/g/collect" attempt; if the container has loaded and the
	 * Google tag is still silent after a grace period, it recovers the visit
	 * through the existing server container pipeline: a minimal, standards-
	 * shaped GA4 page_view hit first, then every data-layer event the plugin
	 * pushed (view_item, view_item_list, purchase, ... - names matched by the
	 * plugin's custom event suffix, suffix stripped, ecommerce translated to
	 * GA4 protocol: items[] -> pr1..prN, currency -> cu, value -> epn.value,
	 * transaction_id/tax/shipping/coupon). Already-pushed events are replayed
	 * once; a dataLayer.push hook (installed ONLY after the Google tag is
	 * declared dead, so healthy pages are never touched) forwards later ones
	 * immediately. Every hit carries the "synapse_recovered" event parameter
	 * so recovered traffic stays identifiable in GA4 and in the server logs.
	 *
	 * In normal browsers the Google tag sends within a couple of seconds, the
	 * watchdog sees it, and does nothing - zero behavior change. If the
	 * container never loads (blocked, or consent-gated and never granted) the
	 * watchdog never arms, so no unconsented hit is ever produced.
	 *
	 * Proof of death. Waiting is what keeps the watchdog from doubling a slow
	 * but healthy Google tag, but waiting alone would lose every visit shorter
	 * than the pagehide guard (2.5 s after arming): Brave Shields replace
	 * Google's tag script with a stand-in that never starts, a content blocker
	 * in Safari refuses it outright, and a visitor clicking on after 2-3 s
	 * would get no GA4. So the watchdog also watches the Google tag SCRIPT for
	 * its own measurement id (".../gtag/js?id=<id>", any host, id
	 * case-insensitive; a ".../gtag/destination?id=<id>" script counts on
	 * refusal only), with capture listeners on document - "load" never reaches
	 * window. The tag is proven dead when every such script has either failed
	 * to load (error), or loaded more than 1 s ago without the real code having
	 * started: no google_tag_manager[<id>], and no
	 * google_tag_data.tidr.container[<id>] with state 2, which GTM sets for
	 * every alias of a combined Google tag that is running. The stand-in rule
	 * is off while Tag Assistant is attached. A script still loading, or one
	 * printed before the watchdog ran (its outcome unknown), rules proof out.
	 * With proof and the container up, leaving the page and the tab going to
	 * the background recover at once: Google's tag cannot send on this page, so
	 * nothing can be doubled. The 8 s timer gives the consent banner its grace
	 * period; it also waits while a Google tag script is still loading, and for
	 * 2.5 s after a late one loads, up to 20 s after arming, instead of
	 * doubling a slow tag. Nothing arms or fires while the page is prerendered,
	 * and a pagehide into the back/forward cache with a script still loading
	 * does not fire. Every recovered hit carries "_synw" = <reason>.<trigger>
	 * (err | standin | silent . pagehide | hidden | timer) as a plain query
	 * parameter, so the server logs show why each recovery happened. It is not
	 * an event parameter (no "ep."), so GA4 ignores it: sGTM passes such
	 * parameters on unchanged, as it does the worker's "_se".
	 *
	 * Consent
	 * state is read from the dataLayer's gtag consent entries and mirrored
	 * into the hit (gcs/npa); session continuity uses sessionStorage when
	 * analytics consent is granted and falls back to per-page values.
	 *
	 * Emitted after the ad-blocker shim inside the same script tag: the
	 * watchdog's observers wrap on top of the shim's wrappers (seeing plain
	 * URLs before encoding), while its own recovery hit is sent through a
	 * fetch reference captured after the shim installed - so it is encoded
	 * like every other hit. Fails open: any error leaves the page untouched.
	 *
	 * There is no separate switch: the watchdog ships exactly when a GA4
	 * Measurement ID is set. It is always this inline form, which carries its
	 * own observers.
	 *
	 * The measurement id and the raw container id travel base64-encoded (see
	 * encode_id()) and dec() decodes them at runtime: a value containing "-" is
	 * a plain id and passes through, anything else goes through atob(), and a
	 * value that does not decode reads as "", which switches the watchdog off
	 * instead of arming it against a wrong key. The page therefore carries no
	 * readable Google id, while every request the watchdog sends is byte for
	 * byte the same as with plain ids.
	 *
	 * @return string Bare IIFE, or '' when the feature is off/unconfigured.
	 */
	// gt() reads the original address of a script element the shield rewrote
	// (window.__synSrc) before its src, so a disguised Google tag script is judged
	// exactly like a plain one. Every recovery, by the timer, on pagehide or when
	// the page is hidden, first reads the browser's resource timing (heard(), the
	// last gate in fire()): a GA4 hit the Google tag sent on its own, before the
	// watchdog installed or while it waited, is never doubled. The timing keeps a
	// request's address and nothing of its body, so it counts a hit only when the
	// address itself names this site's GA4 id (ga4()'s third argument). An address
	// without an id is unknown to it and stops no recovery: read as ours, a hit of
	// another property that carries its id in the body would silence the recovery
	// of this one. The hooks on fetch, sendBeacon and XMLHttpRequest see the body
	// and judge by it.
	private function get_ga4_fallback_js() {
		// ---- SHIELD PIECE BEGIN ----
		$tid = GTM_Server_Side_Helpers::get_ga4_fallback_id();
		if ( '' === $tid ) {
			return '';
		}

		$prefix = wp_parse_url( GTM_Server_Side_Helpers::get_gtm_container_url(), PHP_URL_PATH );
		$prefix = is_string( $prefix ) ? rtrim( $prefix, '/' ) : '';
		if ( '' === $prefix ) {
			return '';
		}

		// The RAW web container id ("GTM-XXXX") - the watchdog polls
		// window.google_tag_manager[<id>] to know the container has run.
		// (get_gtm_container_id() would return the obfuscated loader query
		// when the custom loader is active, which never appears as a key.)
		$container_id = GTM_Server_Side_Helpers::get_raw_gtm_container_id();
		if ( empty( $container_id ) ) {
			return '';
		}

		// Both ids are camouflaged in the page (encode_id) and decoded by dec().
		$tid_js    = wp_json_encode( self::encode_id( $tid ) );
		$prefix_js = wp_json_encode( $prefix );
		$cid_js    = wp_json_encode( self::encode_id( $container_id ) );

		// Suffix the plugin appends to its data-layer event names ("_synapse");
		// the watchdog recovers exactly those events, suffix stripped. When the
		// custom naming is off it falls back to a whitelist of standard names.
		$suffix_js = wp_json_encode( GTM_Server_Side_Helpers::is_enable_data_layer_custom_event_name() ? GTM_SERVER_SIDE_DATA_LAYER_CUSTOM_EVENT_NAME : '' );

		return '(function(w){try{if(w.__synGa4Fb)return;w.__synGa4Fb=1;function dec(v){if("string"!==typeof v||""===v)return"";if(-1!==v.indexOf("-"))return v;try{return w.atob(v)}catch(x){return""}}var T=dec(' . $tid_js . '),P=' . $prefix_js . ',C=dec(' . $cid_js . '),SUF=' . $suffix_js . ',G=8000,O=w.location&&w.location.origin;if(!O||!T||!C)return;var seen=false,sent=false,stopped=false,armT=0,hn=0,di=0,hooked=false,ses=null,con=null,nav=w.navigator,doc=w.document,eu=encodeURIComponent,why="",gts=[],TU=T.toUpperCase(),WL={view_item:1,view_item_list:1,select_item:1,add_to_cart:1,remove_from_cart:1,view_cart:1,begin_checkout:1,add_payment_info:1,add_shipping_info:1,purchase:1,refund:1,search:1,login:1,sign_up:1};function bt(b){try{if("string"===typeof b)return b;if(w.URLSearchParams&&b instanceof w.URLSearchParams)return b.toString()}catch(x){}return ""}function ga4(u,b,k){try{var a=new URL(u,w.location.href);if(a.origin!==O||a.pathname.indexOf("/g/collect")===-1)return false;var t=a.searchParams?a.searchParams.get("tid"):null;if(!t){var s2=bt(b);if(s2){var m2=/(?:^|[&\n])tid=([^&\n]*)/.exec(s2);if(m2)t=decodeURIComponent(m2[1])}}return t?t===T:!k}catch(x){return false}}var IF=w.fetch?w.fetch.bind(w):null;if(w.fetch){var of=w.fetch;w.fetch=function(i,o){try{var u=(typeof i==="string"||(w.URL&&i instanceof w.URL))?String(i):(i&&i.url);if(u&&ga4(u,o&&o.body))seen=true}catch(x){}return of.apply(w,arguments)}}if(nav&&nav.sendBeacon){var sb=nav.sendBeacon.bind(nav);nav.sendBeacon=function(u,d){try{if(ga4(u,d))seen=true}catch(x){}return sb.apply(nav,arguments)}}var X=w.XMLHttpRequest;if(X&&X.prototype&&X.prototype.open&&X.prototype.send){var oo=X.prototype.open;X.prototype.open=function(){try{this.__synU=arguments[1]}catch(x){}return oo.apply(this,arguments)};var os=X.prototype.send;X.prototype.send=function(){try{if(this.__synU&&ga4(this.__synU,arguments[0]))seen=true}catch(x){}return os.apply(this,arguments)}}function heard(){try{var pe=w.performance&&w.performance.getEntriesByType?w.performance.getEntriesByType("resource"):null;if(pe)for(var i9=0;i9<pe.length;i9++)if(pe[i9]&&ga4(String(pe[i9].name),0,1)){seen=true;return true}}catch(x){}return false}heard();function up(){try{return!!(w.google_tag_manager&&w.google_tag_manager[C])}catch(x){return false}}function gt(el){try{if(!el||!el.src||"SCRIPT"!==String(el.tagName||"").toUpperCase())return 0;var a=new URL((w.__synSrc&&w.__synSrc.get(el))||el.src,w.location.href);if(String(a.searchParams.get("id")||"").toUpperCase()!==TU)return 0;if(/\/gtag\/js$/.test(a.pathname))return 1;if(/\/gtag\/destination$/.test(a.pathname))return 2}catch(x){}return 0}function alive(){try{var m=w.google_tag_manager;if(m&&m[T])return true;var d=w.google_tag_data,c=d&&d.tidr&&d.tidr.container,e=c&&c[T];return!!(e&&2===e.state)}catch(x){return true}}function mark(e,st){try{var el=e&&e.target,k=gt(el);if(!k)return;if(2===k&&1!==st){el.__synGt=3;return}if(!(el.__synGt>0))gts.push(el);el.__synGt=st;el.__synGtT=Date.now()}catch(x){}}function scan(){var r=0;try{var s=doc.getElementsByTagName("script");for(var i=0;i<s.length;i++){if(!gt(s[i]))continue;var v=s[i].__synGt;if(!v)return 1;if(v<0)r=2}}catch(x){}return r}function proof(){try{if(seen||alive()||!gts.length||scan())return"";var r="err",n=Date.now();for(var i=0;i<gts.length;i++){if(2===gts[i].__synGt){if(w["google.tagmanager.debugui2.queue"]||n-gts[i].__synGtT<1000)return"";r="standin"}}return r}catch(x){return""}}function fresh(n){try{if(!alive())return false;for(var i=0;i<gts.length;i++)if(2===gts[i].__synGt&&n-gts[i].__synGtT<2500)return true}catch(x){}return false}try{var s0=doc.getElementsByTagName("script");for(var i0=0;i0<s0.length;i0++)if(gt(s0[i0]))s0[i0].__synGt=-1}catch(x){}if(doc&&doc.addEventListener){doc.addEventListener("error",function(e){mark(e,1)},true);doc.addEventListener("load",function(e){mark(e,2)},true)}function consent(){var got=false,ad=false,an=false;try{var dl=w.dataLayer||[];for(var i=0;i<dl.length;i++){var e=dl[i];if(e&&"consent"===e[0]&&("default"===e[1]||"update"===e[1])&&e[2]&&"object"===typeof e[2]){got=true;if(void 0!==e[2].ad_storage)ad="granted"===e[2].ad_storage;if(void 0!==e[2].analytics_storage)an="granted"===e[2].analytics_storage}}}catch(x){}try{var ics=w.google_tag_data&&w.google_tag_data.ics,en7=ics&&ics.entries;var pick=function(k){var e7=en7&&en7[k];if(!e7)return void 0;if(void 0!==e7.update)return!!e7.update;if(void 0!==e7["default"])return!!e7["default"];return void 0};var a7=pick("ad_storage"),n7=pick("analytics_storage");if(void 0!==a7){got=true;ad=a7}if(void 0!==n7){got=true;an=n7}}catch(x){}if(!got){ad=true;an=true}return{ad:ad,an:an}}function mkid(now){return Math.floor(9e8*Math.random()+1e8)+"."+now}function gaCid(){try{var m9=/(?:^|;\s*)_ga=GA\d+\.\d+\.(\d+\.\d+)/.exec((doc&&doc.cookie)||"");return m9?m9[1]:null}catch(x){return null}}var ephD=null,ephG=null;function ephemeral(an){var n;if(!an){if(!ephD){n=Math.floor(Date.now()/1e3);ephD={cid:mkid(n),sid:n,first:true,fresh:true}}return ephD}if(!ephG){n=Math.floor(Date.now()/1e3);var ga=gaCid();ephG={cid:ga||mkid(n),sid:n,first:!ga,fresh:true}}return ephG}function persisted(){var s=null;try{s=w.sessionStorage}catch(x){return null}if(!s)return null;var now=Math.floor(Date.now()/1e3),st=null;try{st=JSON.parse(s.getItem("_synfb")||"null")}catch(x){st=null}var first=!st||!st.cid,fresh=first;if(first){var ga=gaCid();st={cid:ga||mkid(now),sid:now};if(ga)first=false}try{s.setItem("_synfb",JSON.stringify(st));if(!s.getItem("_synfb"))return null}catch(x){return null}return{cid:st.cid,sid:st.sid,first:first,fresh:fresh}}var pers=null,persTried=false;function state(an){if(!an)return ephemeral(false);if(!persTried){persTried=true;pers=persisted()}return pers||ephemeral(true)}function itstr(a){var M={item_id:"id",item_name:"nm",item_brand:"br",item_variant:"va",item_category:"ca",item_category2:"c2",item_category3:"c3",item_category4:"c4",item_category5:"c5",price:"pr",quantity:"qt",coupon:"cp",discount:"ds",index:"lp",item_list_id:"li",item_list_name:"ln",affiliation:"af"},out=[],i,k,it,p,v;for(i=0;i<a.length&&i<60;i++){it=a[i]||{};p=[];for(k in M){v=it[k];if(v===void 0||v===null||v==="")continue;v=String(v).replace(/~/g," ");if(v.length>100)v=v.slice(0,100);p.push(M[k]+v)}if(p.length)out.push("pr"+(i+1)+"="+eu(p.join("~")))}return out}function ecom(ec){var ex=[];try{if(!ec||"object"!==typeof ec)return ex;if(ec.currency)ex.push("cu="+eu(ec.currency));if(ec.value!==void 0&&ec.value!==null&&ec.value!=="")ex.push("epn.value="+eu(ec.value));if(ec.transaction_id)ex.push("ep.transaction_id="+eu(ec.transaction_id));if(ec.tax)ex.push("epn.tax="+eu(ec.tax));if(ec.shipping)ex.push("epn.shipping="+eu(ec.shipping));if(ec.coupon)ex.push("ep.coupon="+eu(ec.coupon));if(ec.items&&ec.items.length)ex=ex.concat(itstr(ec.items))}catch(x){}return ex}function send(en,extra){if(!IF||stopped)return;try{con=consent();ses=state(con.an);hn++;var q=["v=2","tid="+eu(T),"cid="+eu(ses.cid),"sid="+ses.sid,"sct=1","seg="+(ses.fresh&&1===hn?"0":"1"),"_p="+Math.floor(9e8*Math.random()+1e8),"_s="+hn,"gcs=G1"+(con.ad?"1":"0")+(con.an?"1":"0"),"npa="+(con.ad?"0":"1"),"ul="+eu(((nav&&nav.language)||"").toLowerCase()),"sr="+(w.screen?w.screen.width+"x"+w.screen.height:""),"dl="+eu(w.location.href),"dt="+eu((doc&&doc.title)||""),"en="+eu(en),"ep.synapse_recovered=1"];if(why)q.push("_synw="+eu(why));if(doc&&doc.referrer)q.push("dr="+eu(doc.referrer));if(ses.fresh&&1===hn)q.push("_ss=1","_nsi=1");if(ses.first&&1===hn)q.push("_fv=1");if(extra&&extra.length)q=q.concat(extra);var pre=seen;IF(O+P+"/g/collect?"+q.join("&"),{method:"GET",keepalive:true}).catch(function(){});if(!pre)seen=pre}catch(x){}}function evName(n){if("string"!==typeof n||!n)return null;if(SUF){if(n.length>SUF.length&&n.slice(-SUF.length)===SUF)return n.slice(0,n.length-SUF.length);return null}return WL[n]?n:null}function pump(){try{if(seen)stopped=true;if(stopped){di=(w.dataLayer||[]).length;return}var dl=w.dataLayer||[];for(;di<dl.length;di++){var e=dl[di];if(!e||"object"!==typeof e||Array.isArray(e))continue;var en=evName(e.event);if(!en||"page_view"===en)continue;send(en,ecom(e.ecommerce))}}catch(x){}}function fire(r,t){if(seen||sent||(doc&&doc.prerendering)||heard())return;sent=true;why=(r||"silent")+"."+(t||"timer");try{con=consent();send("page_view");pump();if(!hooked){hooked=true;var dl=w.dataLayer=w.dataLayer||[];var dp=dl.push;dl.push=function(){var r=dp.apply(dl,arguments);try{pump()}catch(x){}return r}}}catch(x){}}function tick(){try{if(seen||sent||heard())return;var n=Date.now();if(n-armT<20000&&((1===scan()&&!alive())||fresh(n))){w.setTimeout(tick,500);return}fire(proof(),"timer")}catch(x){}}var polls=0,pt=w.setInterval(function(){try{if(seen||sent){w.clearInterval(pt);return}if(doc&&doc.prerendering)return;if(w.google_tag_manager&&w.google_tag_manager[C]){w.clearInterval(pt);armT=Date.now();w.setTimeout(tick,G);return}if(++polls>240)w.clearInterval(pt)}catch(x){w.clearInterval(pt)}},500);if(w.addEventListener)w.addEventListener("pagehide",function(e){try{if(seen||sent||!up())return;if(e&&e.persisted&&1===scan())return;var r=proof();if(r){fire(r,"pagehide");return}var n=Date.now();if(armT&&n-armT>2500&&!fresh(n))fire("","pagehide")}catch(x){}});if(doc&&doc.addEventListener)doc.addEventListener("visibilitychange",function(){try{if("hidden"!==doc.visibilityState||seen||sent||!up())return;var r=proof();if(r)fire(r,"hidden")}catch(x){}})}catch(x){}})(window);';
		// ---- SHIELD PIECE END ----
	}

	/**
	 * Print tag script attrs.
	 *
	 * @return string
	 */
	private function print_tag_script_attrs() {
		if ( GTM_Server_Side_Helpers::is_enable_placement_gtm_consent() ) {
			return ' type="text/plain" id="custom-loader"';
		}
		return '';
	}
}
