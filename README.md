# WS Form WPML Helper

Make [WS Form](https://wsform.com) forms translatable via [WPML String Translation](https://wpml.org/documentation/getting-started-guide/string-translation/) — without duplicating forms per language.

WS Form has no native multi-language support. The official WPML guidance is to duplicate every form per language, which means every copy edit has to be repeated N times. This plugin avoids the duplication: it walks each form's structure on save, registers translatable strings with WPML, and swaps in translated values at render time. WS Form keeps a single source of truth; translators work in the WPML String Translation UI like for any other WPML-aware plugin.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- [WS Form](https://wsform.com) (Lite or Pro)
- [WPML Multilingual CMS](https://wpml.org) + **String Translation** add-on

If WS Form or WPML String Translation is missing, the plugin shows an admin notice and stays inactive — no fatal errors.

## Installation

1. Clone or download this repository into `wp-content/plugins/wsform-wpml-helper/`:
   ```bash
   git clone https://github.com/schrittweiter/wsform-wpml-helper.git \
     wp-content/plugins/wsform-wpml-helper
   ```
2. Activate **WS Form WPML Helper** under *Plugins*.
3. After your forms are built, click **Sync translatable strings** on the WS Form list screen (or run `wp wsform-wpml-helper sync`).
4. Translate the registered strings under *WPML → String Translation* — filter by domain **"WS Form WPML Helper"**.

## What gets translated

Auto-discovered from each form, no per-form configuration required:

| Surface | Properties |
|---|---|
| Field copy | `label`, `placeholder`, `help`, `description` |
| Validation | `invalid_feedback`, `required_invalid_feedback` |
| Buttons | `submit_label`, `reset_label`, `next_label`, `previous_label`, `clear_label`, `save_label`, `button_label` |
| Content blocks | `html`, `html_editor`, `message` |
| Choice options | `data_grid_select`, `data_grid_radio`, `data_grid_checkbox` (each row's first column) |
| Show Message action | `action_message_message` |
| Send Email action | `action_email_subject`, `action_email_email_body`, `action_email_email_body_plain`, `action_email_from_name` |
| Save Submission action | `action_database_user_response_email_subject`, `action_database_user_response_email_body`, `action_database_user_response_email_from_name` |

**Not translatable** (by design): URLs, redirect targets, webhook payloads, email recipients (`to`, `cc`, `bcc`, `reply-to`). These don't belong in String Translation, and excluding the recipient fields specifically prevents a translator from redirecting form submissions.

## How it works

- On `wsf_form_publish`, the plugin walks the form object and registers every translatable string with WPML under the context **"WS Form WPML Helper"**.
- On `wsf_pre_render`, strings are swapped in for the active language.
- For action strings (success messages, emails) translation happens via `wsf_actions_post_submit` / `wsf_actions_post_save` so the action handler receives the translated config when it runs.
- Strings are keyed by a stable fingerprint (`form_{id}_field_{id}_{prop}`, `form_{id}_action_{row_index}_{key}`, etc.), so duplicate copy ("Submit" used in five forms) translates independently.
- Re-running sync is idempotent — WPML treats `wpml_register_single_string` as upsert.

## Syncing existing forms

After installing on a site with existing forms, run a one-time sync to register all current strings.

**Admin button** — visit *WS Form → Forms*, click *Sync translatable strings*.

**WP-CLI** —
```bash
wp wsform-wpml-helper sync
```

Re-run sync any time you add or rename fields. The capability required is **`edit_form`** (with a `manage_options` fallback) — same cap WS Form uses for its own form-editing endpoints, so anyone who can edit a form can also sync its strings.

## Filters

| Filter | Purpose |
|---|---|
| `wsform_wpml_helper_form_ids` | Allowlist of form IDs to translate. Empty (default) = all forms. |
| `wsform_wpml_helper_action_meta_keys` | Map of action ID → translatable meta keys. Extend to cover custom actions. |
| `wsform_wpml_helper_sync_capability` | Override the capability required for sync. |

### Examples

Scope translation to specific forms only:

```php
add_filter( 'wsform_wpml_helper_form_ids', function () {
    return array( 12, 34, 56 );
} );
```

Add coverage for a custom action's translatable strings:

```php
add_filter( 'wsform_wpml_helper_action_meta_keys', function ( $map ) {
    $map['my_custom_action'] = array(
        'action_my_custom_message',
    );
    return $map;
} );
```

## Security notes

- **HTML-bearing translations.** Some translatable strings are HTML by design (HTML blocks, field descriptions, the Show Message banner, the email body). Anyone with WPML String Translation editor access can edit the HTML in those translations, and WS Form will render it. Treat the String Translation editor role as a trusted role, on par with a WordPress Editor.
- **Email recipients are not translatable.** Only subject, body, plain-text body and from-name are exposed to translators. Recipient fields (`to`, `cc`, `bcc`, `reply-to`) are deliberately excluded so translators cannot redirect submissions.
- **Extending the translatable surface.** Adding routing fields (e.g. `action_email_to`) to the `wsform_wpml_helper_action_meta_keys` map would let translators change recipients. Only do this if you fully trust your String Translation editors.

## Caveats

- **Reordering actions** in the WS Form admin invalidates the action-string keys (they are indexed by row position). Re-run sync after reordering.
- **Renaming fields** doesn't break anything, but the old string entries become orphaned in WPML. They're harmless and can be cleaned up manually under *WPML → String Translation*.
- **The plugin does not translate field default values, dynamic values, or computed-field expressions** — only user-visible copy.

## Contributing

Issues and pull requests are welcome at https://github.com/schrittweiter/wsform-wpml-helper.

## License

[GPL-2.0-or-later](LICENSE.txt). © schrittweiter GmbH (https://schrittweiter.de).
