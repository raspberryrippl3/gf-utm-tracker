=== UTM Tracker for Gravity Forms ===
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later

Saves UTM parameters, ad click IDs, landing page and referrer with every Gravity Forms entry.

== Description ==

When a visitor arrives, a small script stores the campaign parameters from the URL in a first-party cookie (gfut_data). When they later submit any Gravity Form, the values are saved with the entry.

Captured: utm_source, utm_medium, utm_campaign, utm_term, utm_content, utm_id, gclid, fbclid, msclkid, landing page, external referrer and visit time (UTC).

Where the data shows up:

* Entry screen: a "Campaign Tracking" box in the sidebar.
* Entry list: columns you can add via the column picker, and entry search filters.
* Entry export: each value is an exportable column.
* Notifications and confirmations: merge tags {utm_source}, {utm_medium}, {utm_campaign}, {utm_term}, {utm_content}, {utm_id}, {utm_gclid}, {utm_fbclid}, {utm_msclkid}, {utm_landing_page}, {utm_referrer}, {utm_visit_time}, and {utm_all} for a table of everything captured. All are in the merge tag picker.
* Optional hidden fields: add a Hidden field, tick "Allow field to be populated dynamically", and set Parameter Name to a key such as utm_source. Works with page caching (filled on submit if the cached page rendered it empty).

Settings (Forms > Settings > UTM Tracker): last-touch or first-touch attribution, and how many days to remember (default 30).

Works with full-page caching: capture happens in the browser; a server-side fallback covers uncached pages.

== Developer hooks ==

* gfut_tracked_params (filter): add query parameters to track, e.g. $params['ref'] = 'Referral code'. Each becomes entry meta and a merge tag ({utm_ref}).
* gfut_capture_enabled (filter): return false to stop capture entirely.
* gfut_consent_category (filter): change the consent category the cookie needs (default analytics for the TWD Cookie Banner Plugin, statistics for the WP Consent API).
* gfut_consent_provider (filter): force 'cbp', 'wpconsent' or 'none'.
* gfut_entry_value (filter): change a value before it is saved with an entry.

== Cookie consent ==

The tracking cookie (gfut_data) is only set once the visitor accepts the Analytics category.

* TWD Cookie Banner Plugin: detected automatically. Capture waits for its consent choice, starts on the same page as soon as Analytics is accepted (so the landing visit's UTM tags are kept), and the cookie is deleted if consent is withdrawn. The cookie is registered with the banner, so it is listed in the cookie table and policy with a description, and the banner's own cleanup and server-side cookie blocking cover it.
* WP Consent API (Complianz, CookieYes, Cookiebot and others): used when the TWD banner is not active; the cookie needs "statistics" consent.
* No consent plugin: captures straight away.

If a visitor never accepts, their entries are saved without tracking data.

== Changelog ==

= 1.1.0 =
* Cookie consent support for the TWD Cookie Banner Plugin and the WP Consent API.

= 1.0.0 =
* First release.
