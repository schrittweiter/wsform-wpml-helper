=== WS Form WPML Helper ===
Contributors: schrittweiter
Tags: wsform, wpml, translation, multilingual, string translation
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2+ or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Make WS Form forms translatable via WPML String Translation — without duplicating forms per language.

== Description ==

WS Form has no native multi-language support. The official WPML guidance is to duplicate every form per language, which means every copy edit has to be repeated N times.

This helper plugin avoids the duplication. It walks the form object on save and on render, registers translatable strings with WPML String Translation, and swaps in translated values at render time. It treats WS Form like any other WPML-aware plugin.

= What gets translated =

Auto-discovered from each form, no per-form configuration required:

* Field labels, placeholders, help text, descriptions
* Validation messages (`invalid_feedback`, `required_invalid_feedback`)
* Button labels (submit, reset, next, previous, clear, save, custom)
* HTML block content
* Select / radio / checkbox option labels
* Action strings: Show Message banners, Send Email subject + body + from-name, Save Submission user-response email

= How it works =

* On `wsf_form_publish`, every translatable string is registered with WPML under the context "WS Form WPML Helper".
* On `wsf_pre_render`, strings are swapped in for the active language.
* For action strings (success messages, emails), translation happens via `wsf_actions_post_submit` / `wsf_actions_post_save` so the action handler receives the translated config at submit time.
* Strings are keyed by a stable fingerprint (`form_{id}_field_{id}_{prop}`) so duplicates ("Submit" used in five forms) translate independently.

= Syncing existing forms =

After installing on a site with existing forms, run a one-time sync to register all current strings:

* **Admin button**: visit *WS Form → Forms*, click *Sync translatable strings*
* **WP-CLI**: `wp wsform-wpml-helper sync`

Re-running sync is idempotent — WPML treats `wpml_register_single_string` as upsert.

= Filters =

* `wsform_wpml_helper_form_ids` — return an array of form IDs to scope which forms get processed. Empty (default) = all forms.
* `wsform_wpml_helper_action_meta_keys` — extend the map of translatable action meta keys (e.g. to cover custom actions).

== Requirements ==

* WS Form (any edition)
* WPML Multilingual CMS + String Translation add-on

If either is missing, the plugin shows an admin notice and stays inactive.

== Security notes ==

* **HTML in translations.** Some translatable strings are HTML-bearing by design — HTML blocks, field descriptions, the Show Message banner, the email body. Anyone with WPML String Translation editor access can change the HTML in those translations, and WS Form will render it. Treat the String Translation editor role as a trusted role, on par with a WordPress Editor.
* **Email recipients are not translatable.** Only email subject, body, plain-text body and from-name are exposed to translators. The `to`, `cc`, `bcc`, `reply-to` and similar routing fields are deliberately **not** in the translatable map, so a translator cannot redirect form submissions.
* **Extending the translatable surface.** The `wsform_wpml_helper_action_meta_keys` filter lets PHP code extend which action meta keys are translatable. Adding routing keys (e.g. `action_email_to`) here would let translators change recipients — only do this if you fully trust your String Translation editors.
* **Sync capability.** The "Sync translatable strings" button and `admin-post.php?action=wsform_wpml_helper_sync` endpoint require WS Form's `edit_form` capability (with a `manage_options` fallback). Override via the `wsform_wpml_helper_sync_capability` filter.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/wsform-wpml-helper/`.
2. Activate via *Plugins*.
3. After your forms are built, click *Sync translatable strings* on the WS Form list screen.
4. Translate the registered strings under *WPML → String Translation* (filter by domain "WS Form WPML Helper").

== Changelog ==

= 1.0.0 =
* Initial release.
