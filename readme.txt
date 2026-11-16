=== SheetSync for CF7 ===
Contributors: yourwporgusername
Tags: contact form 7, google sheets, spreadsheet, cf7, leads
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Requires Plugins: contact-form-7
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send Contact Form 7 submissions to Google Sheets in real time with your own Google Service Account. Column mapping, dashboard, no external libraries.

== Description ==

SheetSync for CF7 appends every Contact Form 7 submission as a new row of a Google Sheet, in real time.

It follows a **Bring Your Own Key** model: you create a free Google Service Account in your own Google Cloud project and paste its JSON key in WordPress. No third-party server sits between your site and Google, and no credential is bundled with the plugin.

**Features**

* Plugin page under **Contact → SheetSync for CF7** with two tabs: Settings and Dashboard.
* Built-in 4-step guide to create the Service Account; upload or paste the JSON key, stored encrypted (AES-256-GCM) and never shown again.
* "Copy email" and "Test connection" buttons.
* A "Google Sheets" tab in every Contact Form 7 editor: spreadsheet ID (or full URL), tab name, optional columns (submission date, page URL, IP address), fields not to send.
* **Column mapping**: for every column of your sheet choose a form field, a metadata, a custom text (e.g. "Google Ads" in a "Lead type" column, CF7 mail-tags supported) or leave it empty. Your own columns (notes, follow-up, quotes…) are never touched.
* "Sync headers" adds the missing columns to row 1 without moving or deleting yours.
* Values are matched by header name: reorder the columns freely.
* **Date format** of your choice (e.g. 11/03/2026 14:30) for the submission date and the [date] fields, so Google Sheets stores real dates.
* **CF7 pipes** (`Label|value`): write the value, the label or both.
* **Dashboard**: daily chart, totals, searchable list of entries, entry inspector, "send again" for failed entries, CSV export.
* Local archive of the submissions with configurable retention: nothing is lost if Google is unreachable.
* **Lead source tracking**: UTM parameters, ad click IDs (gclid/gbraid/wbraid, fbclid, msclkid) and a computed channel (Google Ads, Meta Ads, Organic search, Direct…) with customizable names, as sheet columns and as CF7 mail-tags (e.g. [_ss_channel]). First-party, no cookies, optional WP Consent API support.
* **Automatic retry**: failed submissions are sent again in the background with increasing intervals (1, 2, 4, 8… hours), up to 3, 5 or 10 attempts.
* **Email alerts** when a submission cannot be sent (at most one per hour, never containing the submitted data), with a test button.
* Protection against formula injection; leading zeros are preserved (ZIP codes, phone numbers).
* Data is sent after the response is delivered to the visitor (on PHP-FPM / LiteSpeed): Google latency never slows down your forms.
* No Composer, no Google SDK: only the WordPress HTTP API and OpenSSL. Clean uninstall.

== Installation ==

1. Install and activate Contact Form 7.
2. Upload the `sheetsync-cf7` folder to `/wp-content/plugins/` (or upload the zip from Plugins → Add New → Upload Plugin) and activate it.
3. Go to **Contact → SheetSync for CF7** and follow the quick start guide to create a Service Account and upload its JSON key.
4. Share your Google Sheet with the Service Account email as **Editor**.
5. Edit a form, open the **Google Sheets** tab, enable it, enter the Spreadsheet ID and the tab name, save the form, click **Sync headers**, then **Load columns from the sheet** to set the column mapping, and save the form again.

== Frequently Asked Questions ==

= Do I need a paid Google Cloud account? =

No. The Google Sheets API is free within Google's usage quotas and does not require a billing account.

= Where is the data sent? =

Only to Google (oauth2.googleapis.com and sheets.googleapis.com), directly from your server, into the spreadsheet you choose. A copy is also kept in your WordPress database if the entries archive is enabled.

= I get "Access denied" =

Open the spreadsheet, click Share and add the Service Account email (shown in Contact → SheetSync for CF7) as Editor.

= I added a field to my form =

Save the form and click "Sync headers" again.

= I deleted a column from the sheet: will it come back? =

No. Columns are only added when you click "Sync headers" (or automatically when row 1 is completely empty). To stop sending a field, tick it in "Fields not to send".

= Dates are written as text in my sheet =

Google Sheets converts a value into a date only when it matches the locale of the spreadsheet (File → Settings → Locale). Choose the matching format in Contact → SheetSync for CF7 → Date and time format: 11/03/2026 for Italy, 03/11/2026 for the United States, or 2026-03-11, which works everywhere.

= My select uses pipes (Label|value): which one is written? =

By default the value after the "|", as in the Contact Form 7 emails. In the Google Sheets tab of the form you can choose the label or both ("Label | Value").

= How do I write a fixed value, like the lead source? =

In the "Column mapping" section choose "Custom text…" for that column and type the value. Mail-tags such as [your-name] or [_post_title] are replaced with the submission data.

= A submission did not arrive in the sheet =

Open the Dashboard tab: the entry shows the error and a "Send to Google Sheets again" button. With `WP_DEBUG_LOG` enabled, errors are also written to `wp-content/debug.log` with the prefix `[SheetSync for CF7]`.

= Will I know if a submission does not reach the sheet? =

Yes. By default you get an email (to the site admin email or the recipients you choose) when a submission fails, and the plugin retries it automatically about once an hour with increasing intervals. You are emailed again only if the last attempt fails too. The retries use WP-Cron: if your host disables it (DISABLE_WP_CRON), set up a server cron job that calls wp-cron.php.

= How does the lead source tracking work? =

When enabled (Contact → SheetSync for CF7 → Settings), a small script reads the UTM parameters and the ad click IDs of the page where the visitor lands, keeps them in the browser's localStorage (key `ss_cf7_attribution`, for the chosen number of days; only with marketing consent if you enable the WP Consent API option) and sends them with the form. With Google Ads auto-tagging the gclid is enough to classify the lead as "Google Ads". The landing page and the referrer are stored without their query string.

= Does it work if the WordPress security keys change? =

The stored key is encrypted with your WordPress salts. If they change, the plugin asks you to upload the JSON key again.

== External services ==

This plugin connects to Google APIs to send form submissions to the Google Sheet configured by the site administrator. Nothing is sent until an administrator uploads a Service Account key and enables a form.

* **Google OAuth 2.0** (`https://oauth2.googleapis.com/token`): a JWT signed with your Service Account key is exchanged for a temporary access token. Sent: Service Account email, requested scope, timestamps. When: on the first submission/test and then about once per hour.
* **Google Sheets API v4** (`https://sheets.googleapis.com`): reads the header row and appends one row per submission. Sent: the values of the form fields, the custom texts of the column mapping and, if enabled, submission date, page URL and visitor IP address. When: on every submission of an enabled form, and when an administrator clicks "Sync headers", "Load columns from the sheet" or "Send to Google Sheets again".

Google Terms of Service: https://policies.google.com/terms
Google APIs Terms of Service: https://developers.google.com/terms
Google Privacy Policy: https://policies.google.com/privacy

Site owners are responsible for informing visitors (e.g. in their privacy policy) that form data is stored in Google Sheets and, when the archive is enabled, in the WordPress database. When the lead source tracking is enabled, the campaign parameters of the landing page are kept in the visitor's browser (localStorage) and sent with the form; no data is sent to third parties by this feature.

== Changelog ==

= 1.4.0 =
* Fixed: the plugin page was missing from the "Contact" menu with recent versions of Contact Form 7 (new "Dashboard" menu).
* New: date and time format settings (default 11/03/2026 14:30), also applied to the [date] fields; formatted dates are written as real dates.
* New: per-form handling of CF7 pipes (value, label or both).

= 1.3.0 =
* New: lead source tracking (UTM parameters, gclid/gbraid/wbraid, fbclid, msclkid, landing page, referrer) with first/last click attribution and WP Consent API support.
* New: "Lead source" channel column (Google Ads, Meta Ads, Organic search, Direct…) with customizable names.
* New: mail-tags [_ss_channel], [_ss_utm_source], [_ss_utm_campaign], [_ss_gclid]… for the CF7 emails and the custom texts of the mapping.

= 1.2.0 =
* New: automatic retry of failed (and interrupted) submissions with exponential backoff, configurable maximum attempts.
* New: email alerts on failures, throttled to one per hour, with configurable recipients and a test email.
* New: the entry inspector shows when the next automatic attempt will run.

= 1.1.0 =
* New: plugin page moved to Contact → SheetSync for CF7, with Settings and Dashboard tabs.
* New: column mapping per form (form field, metadata, custom text with mail-tags, or empty).
* New: "Fields not to send" option.
* New: Dashboard with daily chart, totals, filters, entry inspector, resend and CSV export.
* New: local entries archive with automatic retention.
* Changed: columns are no longer re-added automatically when the sheet already has headers.

= 1.0.0 =
* First release.
