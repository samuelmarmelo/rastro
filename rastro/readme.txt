=== Rastro ===
Contributors: samuelmarmelo
Tags: lgpd, cookie consent, woocommerce, utm, analytics
Requires at least: 6.3
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Privacy-first tracking for WordPress and WooCommerce: LGPD cookie consent, tags after consent, store events and sales by UTM source.

== Description ==

Rastro is a tracking plugin built for Brazil's LGPD (similar to the GDPR). It asks for cookie consent, loads marketing and analytics tags only after the visitor accepts the right purpose, sends WooCommerce events without personal data, and reports your sales by traffic source. The admin screens are in Brazilian Portuguese.

**Consent**

* Three modes: LGPD (default, nothing before "Accept"), auto-accept and basic. The settings screen warns that only the LGPD mode meets the LGPD.
* Google Consent Mode v2, the WP Consent API, or the cookie banner your site already has.
* Revoking consent deletes the known tracking cookies and reloads the page.

**Tags**

* Google Tag Manager, Google Analytics 4, Google Ads (with purchase conversion), Meta Pixel, TikTok Pixel, OpenAI Ads and PostHog.
* Each tag loads only after its purpose (analytics or advertising) is accepted, and off the critical rendering path: when the browser is idle, on the first interaction, or right after consent.
* Meta Pixel base code in the head, as Meta asks: only the fbq queue (no request to Meta) before consent; in basic mode, the full base code and the noscript image.

**WooCommerce events**

* view_item_list, view_item, add_to_cart, remove_from_cart, view_cart, begin_checkout, add_payment_info, purchase and search, in the GA4 format and mapped to Meta, TikTok and PostHog.
* No personal data. Each purchase is sent once.

**Sales by source**

* UTM parameters and click IDs (only which one came, never its value) saved on each order, first and last visit.
* Sales report by traffic source, landing page and each UTM field, with a chart, CSV and Excel export.

**Domain verification**

* Google Search Console, Meta (Facebook) and Pinterest meta tags, and a plain .txt verification file at the site root.

**Logs**

* Rastro errors in PostHog Logs (server, without personal data, and browser after consent).

**Performance**

* No libraries, no build step, no CDN for the plugin itself. Scripts are kept out of the delay of LiteSpeed Cache, WP Rocket and Autoptimize.
* Same HTML for every visitor, safe for page cache.

**Português**

Rastreamento com consentimento de cookies (LGPD), tags só depois do aceite, eventos do WooCommerce sem dado pessoal e relatório de vendas por origem (UTM). Painel em português do Brasil.

== Installation ==

1. Install the plugin from Plugins, Add New, or upload the zip in Plugins, Add New, Upload Plugin.
2. Activate it and open the Rastro menu.
3. Paste the IDs of the tags you use (General tab) and choose the consent mode (Consent tab).

== Frequently Asked Questions ==

= Does anything load before consent? =

In the LGPD mode, no third-party script, pixel or request loads before the visitor accepts the matching purpose. The head only carries the Consent Mode defaults (denied) and local queues.

= Does it send personal data? =

No name, e-mail, phone, address, customer ID, IP or order key is sent to third parties by Rastro. The order number is sent only as the purchase transaction ID, to avoid counting a purchase twice.

= Does it work without WooCommerce? =

Yes. Consent, tags and domain verification work on any WordPress site. Store events and reports appear only with WooCommerce.

== External services ==

Rastro connects to the services below only when you paste the matching ID or key in its settings. In the LGPD mode, the browser scripts load only after the visitor accepts the matching purpose.

* Google Tag Manager, Google Analytics 4 and Google Ads (www.googletagmanager.com): loads Google's tag scripts and sends page views and store events (product IDs, names, prices, quantities, order total and order number). [Terms](https://policies.google.com/terms), [Privacy Policy](https://policies.google.com/privacy).
* Meta Pixel (connect.facebook.net, www.facebook.com): loads the Meta Pixel and sends page views and store events. [Terms](https://www.facebook.com/legal/terms), [Privacy Policy](https://www.facebook.com/privacy/policy/).
* TikTok Pixel (analytics.tiktok.com): loads the TikTok Pixel and sends page views and store events. [Terms](https://ads.tiktok.com/i18n/official/policy/commercial-terms-of-service), [Privacy Policy](https://www.tiktok.com/legal/page/row/privacy-policy/en).
* OpenAI Ads pixel (bzrcdn.openai.com): loads the OpenAI pixel and sends page views. [Terms](https://openai.com/policies/terms-of-use/), [Privacy Policy](https://openai.com/policies/privacy-policy/).
* PostHog (us.i.posthog.com or eu.i.posthog.com): loads PostHog in the browser and sends page views, store events and, if enabled, Rastro logs; the server sends Rastro error logs without personal data. [Terms](https://posthog.com/terms), [Privacy Policy](https://posthog.com/privacy).

Domain verification tags (Google, Meta, Pinterest) and the verification file only print the value you paste; they do not connect anywhere.

== Changelog ==

= 1.1.0 =
* Rastro logs in PostHog Logs (server without personal data, browser after consent).
* Meta Pixel base code in the head as Meta asks (only the queue before consent; full code and noscript in basic mode).
* Verifications tab: Google, Meta (Facebook) and Pinterest meta tags and a .txt verification file.
* TikTok: base code in the head in basic mode, consent revocation and content_ids in events.

= 1.0.0 =
* First public version: consent (LGPD, auto-accept and basic), Consent Mode v2, GTM, GA4, Google Ads, Meta, TikTok, OpenAI Ads and PostHog after consent, WooCommerce events, order attribution and sales reports by source.
