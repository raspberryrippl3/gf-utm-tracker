# UTM Tracker for Gravity Forms

A lean WordPress plugin that captures UTM parameters, ad click IDs (gclid, fbclid, msclkid), the landing page and the external referrer when a visitor arrives, keeps them in a first-party cookie, and saves them with every Gravity Forms entry.

- **Entry screen:** a "Campaign Tracking" box in the sidebar.
- **Entry list and export:** each value is entry meta, so it can be added as a column, filtered on and exported.
- **Notifications and confirmations:** merge tags such as `{utm_source}`, `{utm_campaign}`, `{utm_landing_page}`, `{utm_referrer}`, plus `{utm_all}` for a table of everything captured.
- **Optional hidden fields:** set a Hidden field's dynamic population Parameter Name to e.g. `utm_source`.
- **Settings:** Forms > Settings > UTM Tracker (last-touch or first-touch attribution, cookie lifetime).
- **Cookie consent:** waits for Analytics consent from the TWD Cookie Banner Plugin (or any WP Consent API banner) before setting its cookie, and registers the cookie with the banner.
- Works with full-page caching (capture runs in the browser, with a server-side fallback).

See `readme.txt` for details and developer hooks.

## Install

Zip this folder as `gf-utm-tracker` and upload via Plugins > Add New > Upload, or copy it into `wp-content/plugins/`. Requires Gravity Forms 2.5+.
