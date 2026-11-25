# Synapse Server-Side Tracking

WordPress plugin for server-side tagging through a self-hosted Google Tag Manager server
container: first-party custom loader, enhanced ad-blocker protection, content-blocker
detection, GA4 measurement recovery, WooCommerce data layer events and click ID restoration.
Free, GPL-2.0+.

## Install

Download the latest `updates/synapse-server-side-tracking-<version>.zip` and upload it in
WordPress under Plugins > Add New > Upload Plugin. The plugin updates itself:
it checks `updates/manifest.json` in this repository through WordPress's own update
mechanism and installs new versions like any other plugin. Every package is signed with an
Ed25519 key that never leaves the release machine; the plugin refuses a package that does not
verify against the public key built into it.

## Layout

- `synapse-server-side-tracking/` - the plugin, exactly what the zip contains
- `updates/` - the update manifest and the signed release packages WordPress installs from
