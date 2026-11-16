# SheetSync for CF7

Send **Contact Form 7** submissions to **Google Sheets** in real time, with your own Google Service Account (Bring Your Own Key). No third-party servers, no Composer, no Google SDK.

> WordPress plugin · PHP 8.0+ · WordPress 6.2+ · Contact Form 7 required · GPL-2.0-or-later

## Features

- **Bring Your Own Key**: upload the JSON key of a Google Service Account. It is stored encrypted (AES-256-GCM) and never shown again. A 4-step guide and a "Test connection" button are included.
- **Per-form settings** in a "Google Sheets" tab of the Contact Form 7 editor: spreadsheet, tab, extra columns, fields not to send.
- **Column mapping**: for every column of the sheet choose a form field, a metadata, a custom text (CF7 mail-tags supported) or nothing. Your own columns (notes, follow-up, quotes…) are never touched.
- **Dashboard** (Contact → SheetSync for CF7): daily chart, totals, searchable entries, entry inspector, resend, CSV export.
- **Reliability**: local archive of the submissions, automatic retry with exponential backoff, email alerts (at most one per hour, no personal data).
- **Lead source tracking**: UTM parameters, gclid/gbraid/wbraid, fbclid, msclkid, landing page, referrer and a computed channel (Google Ads, Meta Ads, Organic search, Direct…) with custom names. First-party, no cookies, WP Consent API support. Also available as CF7 mail-tags (`[_ss_channel]`, `[_ss_utm_campaign]`…).
- **Date format** of your choice (e.g. `11/03/2026 14:30`) for the submission date and `[date]` fields, written as real Google Sheets dates.
- **CF7 pipes** (`Label|value`): write the value, the label or both.
- **Safe by default**: formula-injection protection, leading zeros preserved, nonces and capability checks everywhere, clean uninstall.
- **Fast**: data is sent after the response has been delivered to the visitor (PHP-FPM / LiteSpeed).
- Translations: English, Italian.

## Installation

1. Download the zip of the latest [release](../../releases) (or build it, see below).
2. In WordPress: **Plugins → Add New → Upload Plugin**, choose the zip and activate it. Contact Form 7 must be active.
3. Open **Contact → SheetSync for CF7** and follow the quick start guide:
   1. create a Google Cloud project and enable the Google Sheets API;
   2. create a Service Account and download its JSON key;
   3. upload the key and click **Test connection**;
   4. share your spreadsheet with the Service Account email as **Editor**.
4. Edit a form, open the **Google Sheets** tab, enter the spreadsheet URL and the tab name, save, then use **Sync headers** and **Load columns from the sheet**.

## Developers

### Hooks

| Hook | Type | Purpose |
|---|---|---|
| `ss_cf7_row_values` | filter | Values of a submission before the mapping |
| `ss_cf7_columns` | filter | Columns of a form (key → header) |
| `ss_cf7_cell_value` | filter | A cell value after formula protection |
| `ss_cf7_datetime_format` | filter | PHP format of the submission date |
| `ss_cf7_header_aliases` | filter | Header texts recognised as metadata columns |
| `ss_cf7_excluded_tag_types` | filter | CF7 tag types never sent |
| `ss_cf7_auto_add_headers` | filter | Add missing columns on submission (default: only on an empty sheet) |
| `ss_cf7_header_cache_ttl` | filter | Cache the header row (seconds, default 0) |
| `ss_cf7_finish_request_early` | filter | Flush the response before calling Google (default true) |
| `ss_cf7_channel` | filter | Detected lead source channel |
| `ss_cf7_retry_batch_size` | filter | Entries resent per cron run (default 20) |
| `ss_cf7_alert_interval` | filter | Minimum seconds between alert emails |
| `ss_cf7_csv_delimiter` | filter | CSV export delimiter |
| `ss_cf7_menu_parent` | filter | Parent menu of the plugin page |
| `ss_cf7_row_appended` | action | A row was appended |
| `ss_cf7_append_failed` | action | A row could not be appended |
| `ss_cf7_delivery_failed` | action | A delivery attempt failed (with source and attempt count) |

### Tests

The functional tests run without WordPress (WordPress, Contact Form 7 and Google are stubbed):

```bash
php tests/run.php
```

The `openssl` and `mbstring` extensions are required. On Windows builds of PHP you may need to pass the path of `openssl.cnf` as second argument. GitHub Actions runs the lint and the tests on PHP 8.0–8.4.

### Build the plugin zip

```bash
python bin/build-zip.py
```

The zip is written to `dist/` without the development files listed in `.distignore`.

## Privacy

The plugin talks only to `oauth2.googleapis.com` and `sheets.googleapis.com`, directly from your server, and only after an administrator has configured a key and enabled a form. When the lead source tracking is enabled, campaign parameters are kept in the visitor's browser (localStorage) and sent with the form. Site owners must describe these processings in their privacy policy.

## License

[GPL-2.0-or-later](LICENSE). © ReNova s.r.l.s.

Contact Form 7 and Google Sheets are trademarks of their respective owners; this project is not affiliated with or endorsed by them.
