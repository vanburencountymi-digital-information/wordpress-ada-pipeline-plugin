# wordpress-ada-pipeline-plugin

Submits uploaded PDFs to [dice-document-pipeline-api](https://github.com/vanburencountymi-digital-information/dice-document-pipeline-api),
an external ADA remediation pipeline, for accessibility checking, then shows a color-coded status
badge in the Media Library once the result comes back. Works on any WordPress site — no dependency
on any other plugin.

## Requirements

- PHP 7.4 or higher — the floor WordPress will allow activation on (`Requires PHP` in the
  plugin header, mirrored in `composer.json`).
- Actively tested against PHP 8.3 only, matching the current production target. 7.4–8.2 are
  allowed to install but are not verified by CI (see `Dockerfile`) — if you hit a version-specific
  bug on one of those, please report it.

## Installation

Install like any normal WordPress plugin: copy this repo into `wp-content/plugins/`, then activate
it from the WordPress admin (Plugins screen). It does nothing until configured (below).

## Configuration

Add these to `wp-config.php`:

```php
define('ADA_REMEDIATION_API_BASE_URL', 'https://pipeline.example.org');
define('ADA_REMEDIATION_API_TOKEN', '...');        // token for this site's ServiceAccount
define('ADA_REMEDIATION_WEBHOOK_SECRET', '...');   // must match that ServiceAccount's webhook secret
```

All three are required — the plugin stays completely inactive until every one of them is set. Ask
whoever manages the remediation pipeline for a token and webhook secret for your site.

Optional:

```php
define('ADA_REMEDIATION_AUTO_REPLACE_FILE', true); // default false
```

When enabled, the plugin automatically overwrites a PDF's file in place once remediation finishes,
so the Media Library always serves the fixed version. Leave this off if you'd rather review results
manually before replacing anything, or if your site has a more specific integration that handles
file replacement itself (see "For developers" below).

## Usage

Upload a PDF through the normal Media Library. A colored dot appears in the "ADA Remediation
Status" column, updating automatically as the file moves through checking:

| Color | Meaning |
|---|---|
| ⚪ Light blue | Queued — waiting to be sent to the pipeline |
| 🔵 Blue | In progress — the pipeline is checking/remediating it |
| 🟢 Dark green | Compliant |
| 🟢 Light green | Remediated, minor issues remain |
| 🟡 Yellow | Remediated, major issues remain |
| 🔴 Red | Remediated, critical or unclassified issues remain |
| ⚫ Gray | No result available (check failed, or was skipped) |

Hover over the dot for the same information as text.

## For developers

- `AdaRemediationClient\Client::submit_attachment(int $attachment_id, array $context = [])` — call
  this directly to submit any attachment, instead of waiting for the automatic upload trigger.
- `ada_remediation_auto_trigger_on_upload` (filter, `bool`) — return `false` to stop the plugin from
  automatically submitting a PDF on upload, e.g. if your own code calls `submit_attachment()` from a
  richer hook instead.
- `ada_remediation_result` (action, `int $attachment_id, array $result`) — fires once per processed
  webhook, after the badge is updated. `$result` includes `status`, `badge`, `pipeline_version`,
  `verification_results`, and `download_url` (the remediated file, when one exists).
- `ada_remediation_suppress_file_replacement` (filter, `bool`) — return `true` to stop the plugin's
  own `ADA_REMEDIATION_AUTO_REPLACE_FILE` behavior for a given result, e.g. if you're replacing the
  file yourself in a hook on `ada_remediation_result`.

## Testing

```
make build    # build the Docker image (once)
make install  # install Composer dependencies (once, and after editing composer.json)
make test     # run the test suite
```
