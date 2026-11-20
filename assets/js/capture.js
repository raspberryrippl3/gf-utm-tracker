/**
 * UTM Tracker for Gravity Forms: stores campaign parameters from the landing
 * URL in a first-party cookie so they reach a form submitted on a later page.
 */
(function (window, document) {
	'use strict';

	var cfg = window.gfutConfig;
	if (!cfg || !cfg.cookie) {
		return;
	}

	var maxLen = cfg.maxLength || 500;

	function readCookie() {
		var parts = document.cookie ? document.cookie.split('; ') : [];
		for (var i = 0; i < parts.length; i++) {
			var eq = parts[i].indexOf('=');
			if (parts[i].substring(0, eq) === cfg.cookie) {
				try {
					var data = JSON.parse(decodeURIComponent(parts[i].substring(eq + 1)));
					return data && typeof data === 'object' ? data : null;
				} catch (e) {
					return null;
				}
			}
		}
		return null;
	}

	function writeCookie(data) {
		var maxAge = Math.round((cfg.days || 30) * 86400);
		var cookie = cfg.cookie + '=' + encodeURIComponent(JSON.stringify(data)) +
			'; path=/; max-age=' + maxAge + '; SameSite=Lax';
		if (window.location.protocol === 'https:') {
			cookie += '; Secure';
		}
		document.cookie = cookie;
	}

	function trim(value) {
		return String(value).replace(/[\u0000-\u001f<>]/g, '').trim().substring(0, maxLen);
	}

	function getParams() {
		var found = {};
		var query = window.location.search.substring(1);
		if (!query) {
			return found;
		}
		var pairs = query.split('&');
		for (var i = 0; i < pairs.length; i++) {
			var eq = pairs[i].indexOf('=');
			if (eq < 1) {
				continue;
			}
			var key, value;
			try {
				key = decodeURIComponent(pairs[i].substring(0, eq)).toLowerCase();
				value = decodeURIComponent(pairs[i].substring(eq + 1).replace(/\+/g, ' '));
			} catch (e) {
				continue;
			}
			if (cfg.params.indexOf(key) !== -1) {
				value = trim(value);
				if (value) {
					found[key] = value;
				}
			}
		}
		return found;
	}

	function externalReferrer() {
		var ref = document.referrer;
		if (!ref) {
			return '';
		}
		try {
			var host = new URL(ref).hostname.toLowerCase();
			return host && host !== window.location.hostname.toLowerCase() ? ref.substring(0, maxLen * 2) : '';
		} catch (e) {
			return '';
		}
	}

	function pad(n) {
		return (n < 10 ? '0' : '') + n;
	}

	function utcNow() {
		var d = new Date();
		return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate()) + ' ' +
			pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes()) + ':' + pad(d.getUTCSeconds());
	}

	var captured = false;

	function capture() {
		if (captured) {
			return;
		}
		captured = true;

		var existing = readCookie();
		var fromUrl = getParams();
		var hasCampaign = Object.keys(fromUrl).length > 0;

		// Keep what we have when this visit carries no campaign, or when the
		// site uses first-touch attribution.
		if (existing && (!hasCampaign || cfg.attribution === 'first')) {
			return;
		}

		var data = fromUrl;
		data.landing_page = (window.location.origin + window.location.pathname + window.location.search).substring(0, maxLen * 2);
		var ref = externalReferrer();
		if (ref) {
			data.referrer = ref;
		}
		data.visit_time = utcNow();

		writeCookie(data);
	}

	function forget() {
		captured = false;
		document.cookie = cfg.cookie + '=; path=/; max-age=0; SameSite=Lax';
	}

	/* ---- Consent ---- */

	var consent = cfg.consent || { provider: 'none' };
	var category = consent.category;

	// The cookie banner's scanner needs to see the cookie in order to list it.
	if (window.__cbpScanHook || consent.provider === 'none') {
		capture();
		return;
	}

	if (consent.provider === 'cbp') {
		// The banner fires cbp:consent on every page load once a choice is
		// saved, and again whenever the visitor changes it.
		document.addEventListener('cbp:consent', function (e) {
			var cats = e && e.detail && e.detail.categories;
			if (cats && cats[category] === true) {
				capture();
			} else {
				forget();
			}
		});
		// After a scan the banner may hold this script back until consent and
		// run it afterwards, once that event has already fired: ask directly.
		if (window.CBP && typeof window.CBP.hasConsent === 'function' && window.CBP.hasConsent(category)) {
			capture();
		}
		return;
	}

	if (consent.provider === 'wpconsent') {
		var check = function () {
			if (typeof window.wp_has_consent === 'function' && window.wp_has_consent(category)) {
				capture();
			}
		};
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', check);
		} else {
			check();
		}
		document.addEventListener('wp_listen_for_consent_change', function (e) {
			var changed = e && e.detail;
			if (changed && Object.prototype.hasOwnProperty.call(changed, category)) {
				if (changed[category] === 'allow') {
					capture();
				} else {
					forget();
				}
			}
		});
	}
})(window, document);
