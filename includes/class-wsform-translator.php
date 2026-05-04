<?php
/**
 * Auto-translate WS Form forms via WPML String Translation.
 *
 * WS Form has no native multi-language support and the WPML team
 * recommends duplicating forms per language — which means every copy
 * editor change has to be repeated N times. This class avoids the
 * duplication by walking the form object on save (registration) and
 * on render (translation), treating WS Form like any other WPML-aware
 * plugin.
 *
 * Strings are auto-discovered from the form's groups → sections →
 * fields tree, including label, placeholder, help, validation
 * messages, button labels, html-block content and select/radio/
 * checkbox option labels.
 *
 * Strings are keyed by a stable fingerprint (form_id + field_id +
 * property [+ option_index]) so duplicates ("Submit" used in five
 * forms) translate independently. Re-running discovery is idempotent.
 *
 * @package WSForm_WPML_Helper
 */

namespace WSForm_WPML_Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Class WSForm_Translator
 */
class WSForm_Translator {

	/**
	 * Field properties on `$field` itself (not on `$field->meta`) that
	 * carry user-visible copy.
	 *
	 * @var array<int, string>
	 */
	private const FIELD_PROPS = array( 'label' );

	/**
	 * Field meta keys carrying user-visible copy. WS Form stores most
	 * translatable strings under `$field->meta->{key}`.
	 *
	 * @var array<int, string>
	 */
	private const META_PROPS = array(
		'placeholder',
		'help',
		'invalid_feedback',
		'required_invalid_feedback',
		'submit_label',
		'reset_label',
		'next_label',
		'previous_label',
		'clear_label',
		'save_label',
		'button_label',
		'html_editor',
		'html',
		'message',
		'description',
	);

	/**
	 * Meta keys whose value is a data-grid of options (select / radio
	 * / checkbox). Each row's first cell is the visible label.
	 *
	 * @var array<int, string>
	 */
	private const OPTION_META_KEYS = array(
		'data_grid_select',
		'data_grid_radio',
		'data_grid_checkbox',
	);

	/**
	 * Translatable meta keys per action ID. The action ID is the
	 * value of `$config['id']` after `data[1]` is JSON-decoded
	 * (`message`, `email`, `database`, etc.). Anything not listed
	 * here is left alone — URLs, webhook payloads, redirect targets,
	 * etc. don't belong in String Translation.
	 *
	 * Filterable via `wsform_wpml_helper_action_meta_keys` so
	 * downstream projects can extend coverage without forking.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const ACTION_META_KEYS = array(
		// Show Message — the success / info / error banner.
		'message'  => array(
			'action_message_message',
		),
		// Send Email — subject + body. Plain-text body is separate.
		'email'    => array(
			'action_email_subject',
			'action_email_email_body',
			'action_email_email_body_plain',
			'action_email_from_name',
		),
		// Save Submission — optional auto-response to the submitter.
		'database' => array(
			'action_database_user_response_email_subject',
			'action_database_user_response_email_body',
			'action_database_user_response_email_from_name',
		),
	);

	/**
	 * String context passed to WPML. Shows up as the group name in
	 * WPML → String Translation, so make it scannable.
	 */
	private string $context;

	/**
	 * Text domain for the project — used only to localise admin
	 * notices and the rediscovery button label.
	 */
	private string $text_domain;

	/**
	 * Optional allowlist of WS Form form IDs. When non-empty the
	 * translator ignores forms not in the list. Empty means "all
	 * forms". Resolved lazily so the `wsform_wpml_helper_form_ids`
	 * filter can be added after construction.
	 *
	 * @var array<int, int>|null
	 */
	private ?array $form_ids = null;

	/**
	 * @param string         $context     WPML string context (group name).
	 * @param string         $text_domain Text domain for admin UI strings.
	 * @param array<int,int> $form_ids    Optional initial allowlist of form IDs.
	 */
	public function __construct( string $context = 'WS Form WPML Helper', string $text_domain = 'wsform-wpml-helper', array $form_ids = array() ) {
		$this->context     = $context;
		$this->text_domain = $text_domain;

		if ( ! empty( $form_ids ) ) {
			$this->form_ids = array_values( array_map( 'intval', $form_ids ) );
		}
	}

	/**
	 * Wire up hooks. Idempotent — safe to call once during plugin
	 * boot.
	 *
	 * @return void
	 */
	public function register(): void {
		// Translate at render time. Generic hook (no form-ID suffix)
		// so a single closure handles every form.
		add_filter( 'wsf_pre_render', array( $this, 'translate_form' ), 10, 2 );

		// Translate action strings before any action runs. The
		// `wsf_actions_post_{submit|save}` filter receives the full
		// list of actions and the result replaces it — so mutating
		// `$config['meta'][$key]` here is the only place that
		// actually flows through to the action handler at submit
		// time. Per-action `wsf_action_pre_post` is a value-copy
		// dead-end.
		add_filter( 'wsf_actions_post_submit', array( $this, 'translate_actions' ), 10, 3 );
		add_filter( 'wsf_actions_post_save', array( $this, 'translate_actions' ), 10, 3 );

		// Register strings whenever a form is published from the WS
		// Form admin. The form_object is passed in already parsed.
		add_action( 'wsf_form_publish', array( $this, 'register_form_strings' ), 10, 1 );

		// Sync action — bulk-register every form. Triggered by the
		// admin button below or by the WP-CLI command.
		add_action( 'wsform_wpml_helper_sync', array( $this, 'sync_all' ) );

		// Admin: a "Sync translations" button on the WS Form list
		// screen so editors can rediscover after edits without a CLI.
		add_action( 'admin_post_wsform_wpml_helper_sync', array( $this, 'handle_sync_request' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_sync_notice' ) );
		add_action( 'admin_menu', array( $this, 'register_sync_menu' ), 999 );

		// WP-CLI command: `wp wsform-wpml-helper sync`.
		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			\WP_CLI::add_command(
				'wsform-wpml-helper',
				array( $this, 'cli' ),
				array( 'shortdesc' => 'WS Form WPML helper: register every form\'s strings with WPML.' )
			);
		}
	}

	/**
	 * Resolve the active form-ID allowlist. An empty array means "no
	 * scoping — process every form".
	 *
	 * Filter: `wsform_wpml_helper_form_ids` — return an array
	 * of form IDs to limit registration and translation. Useful when
	 * only a few forms are public-facing.
	 *
	 * @return array<int, int>
	 */
	private function allowed_form_ids(): array {
		$ids = $this->form_ids ?? array();

		/**
		 * Allowlist of WS Form form IDs to translate. Empty array =
		 * all forms.
		 *
		 * @param array<int,int> $ids Form IDs.
		 */
		$ids = (array) apply_filters( 'wsform_wpml_helper_form_ids', $ids );

		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	/**
	 * Should this form ID be processed?
	 *
	 * @param int $form_id WS Form ID.
	 */
	private function is_form_allowed( int $form_id ): bool {
		$allowed = $this->allowed_form_ids();
		if ( empty( $allowed ) ) {
			return true;
		}
		return in_array( $form_id, $allowed, true );
	}

	/* -----------------------------------------------------------------
	 * Render-time translation
	 * --------------------------------------------------------------- */

	/**
	 * Filter callback for `wsf_pre_render`. Walks the form object and
	 * replaces every translatable string with its WPML-translated
	 * equivalent.
	 *
	 * @param object $form_object WS Form form object.
	 * @param mixed  $preview     Whether this is a preview render.
	 * @return object
	 */
	public function translate_form( $form_object, $preview ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! is_object( $form_object ) ) {
			return $form_object;
		}

		$form_id = isset( $form_object->id ) ? (int) $form_object->id : 0;
		if ( $form_id <= 0 || ! $this->is_form_allowed( $form_id ) ) {
			return $form_object;
		}

		$this->walk_fields(
			$form_object,
			function ( $field ) use ( $form_id ) {
				$this->translate_field( $field, $form_id );
			}
		);

		return $form_object;
	}

	/**
	 * Translate one field in place.
	 *
	 * @param object $field   Field object.
	 * @param int    $form_id Owning form ID.
	 */
	private function translate_field( object $field, int $form_id ): void {
		$field_id = isset( $field->id ) ? (int) $field->id : 0;
		if ( $field_id <= 0 ) {
			return;
		}

		foreach ( self::FIELD_PROPS as $prop ) {
			if ( ! isset( $field->{$prop} ) ) {
				continue;
			}
			$original = (string) $field->{$prop};
			if ( '' === $original ) {
				continue;
			}
			$field->{$prop} = $this->translate( $this->string_name( $form_id, $field_id, $prop ), $original );
		}

		if ( ! isset( $field->meta ) || ! is_object( $field->meta ) ) {
			return;
		}

		foreach ( self::META_PROPS as $prop ) {
			if ( ! isset( $field->meta->{$prop} ) ) {
				continue;
			}
			$original = (string) $field->meta->{$prop};
			if ( '' === $original ) {
				continue;
			}
			$field->meta->{$prop} = $this->translate( $this->string_name( $form_id, $field_id, $prop ), $original );
		}

		foreach ( self::OPTION_META_KEYS as $option_key ) {
			$rows = $this->option_rows( $field, $option_key );
			if ( null === $rows ) {
				continue;
			}
			foreach ( $rows as $index => $row ) {
				if ( ! isset( $row->data[0] ) ) {
					continue;
				}
				$original = (string) $row->data[0];
				if ( '' === $original ) {
					continue;
				}
				$row->data[0] = $this->translate(
					$this->option_string_name( $form_id, $field_id, $option_key, $index ),
					$original
				);
			}
		}
	}

	/**
	 * Filter callback for `wsf_actions_post_{submit|save}`.
	 *
	 * Walks every action in the list and rewrites translatable
	 * meta-key values (success message, email subject/body, …) to
	 * their WPML-translated equivalents. The returned array replaces
	 * the source array, so the action handler receives the
	 * translated config when it runs.
	 *
	 * Each action carries a `row_index` set by WS Form when the list
	 * is built — we use that as a stable per-action key so multiple
	 * Email actions on the same form translate independently.
	 *
	 * @param array<int, array<string, mixed>> $actions Action configs.
	 * @param object                           $form    Form object.
	 * @param object                           $submit  Submit object.
	 * @return array<int, array<string, mixed>>
	 */
	public function translate_actions( $actions, $form, $submit ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! is_array( $actions ) ) {
			return array();
		}
		$form_id = isset( $form->id ) ? (int) $form->id : 0;
		if ( $form_id <= 0 || ! $this->is_form_allowed( $form_id ) ) {
			return $actions;
		}

		foreach ( $actions as &$config ) {
			if ( ! is_array( $config ) ) {
				continue;
			}
			$action_id = isset( $config['id'] ) ? (string) $config['id'] : '';
			$row_id    = isset( $config['row_index'] ) ? (int) $config['row_index'] : 0;
			if ( '' === $action_id ) {
				continue;
			}
			$keys = $this->action_meta_keys( $action_id );
			if ( empty( $keys ) || ! isset( $config['meta'] ) || ! is_array( $config['meta'] ) ) {
				continue;
			}
			foreach ( $keys as $meta_key ) {
				if ( ! isset( $config['meta'][ $meta_key ] ) ) {
					continue;
				}
				$original = (string) $config['meta'][ $meta_key ];
				if ( '' === $original ) {
					continue;
				}
				$config['meta'][ $meta_key ] = $this->translate(
					$this->action_string_name( $form_id, $row_id, $meta_key ),
					$original
				);
			}
		}
		unset( $config );

		return $actions;
	}

	/* -----------------------------------------------------------------
	 * Registration
	 * --------------------------------------------------------------- */

	/**
	 * Register every translatable string in `$form_object` with WPML.
	 *
	 * @param object $form_object Form object as passed by WS Form on publish.
	 */
	public function register_form_strings( $form_object ): void {
		if ( ! is_object( $form_object ) ) {
			return;
		}
		$form_id = isset( $form_object->id ) ? (int) $form_object->id : 0;
		if ( $form_id <= 0 || ! $this->is_form_allowed( $form_id ) ) {
			return;
		}

		$this->walk_fields(
			$form_object,
			function ( $field ) use ( $form_id ) {
				$this->register_field_strings( $field, $form_id );
			}
		);

		$this->walk_actions(
			$form_object,
			function ( int $row_index, string $action_id, array $config ) use ( $form_id ) {
				$this->register_action_strings( $form_id, $row_index, $action_id, $config );
			}
		);
	}

	/**
	 * Register one action's translatable strings.
	 *
	 * @param int                  $form_id   Owning form ID.
	 * @param int                  $row_index Action's position in the action list (matches `$config['row_index']` at runtime).
	 * @param string               $action_id Action type (e.g. 'message', 'email').
	 * @param array<string, mixed> $config    Decoded action config.
	 */
	private function register_action_strings( int $form_id, int $row_index, string $action_id, array $config ): void {
		$keys = $this->action_meta_keys( $action_id );
		if ( empty( $keys ) ) {
			return;
		}
		if ( ! isset( $config['meta'] ) || ! is_array( $config['meta'] ) ) {
			return;
		}
		foreach ( $keys as $meta_key ) {
			if ( ! isset( $config['meta'][ $meta_key ] ) ) {
				continue;
			}
			$value = (string) $config['meta'][ $meta_key ];
			if ( '' === $value ) {
				continue;
			}
			$this->register_string( $this->action_string_name( $form_id, $row_index, $meta_key ), $value );
		}
	}

	/**
	 * Register one field's strings.
	 *
	 * @param object $field   Field object.
	 * @param int    $form_id Owning form ID.
	 */
	private function register_field_strings( object $field, int $form_id ): void {
		$field_id = isset( $field->id ) ? (int) $field->id : 0;
		if ( $field_id <= 0 ) {
			return;
		}

		foreach ( self::FIELD_PROPS as $prop ) {
			if ( ! isset( $field->{$prop} ) ) {
				continue;
			}
			$value = (string) $field->{$prop};
			if ( '' === $value ) {
				continue;
			}
			$this->register_string( $this->string_name( $form_id, $field_id, $prop ), $value );
		}

		if ( ! isset( $field->meta ) || ! is_object( $field->meta ) ) {
			return;
		}

		foreach ( self::META_PROPS as $prop ) {
			if ( ! isset( $field->meta->{$prop} ) ) {
				continue;
			}
			$value = (string) $field->meta->{$prop};
			if ( '' === $value ) {
				continue;
			}
			$this->register_string( $this->string_name( $form_id, $field_id, $prop ), $value );
		}

		foreach ( self::OPTION_META_KEYS as $option_key ) {
			$rows = $this->option_rows( $field, $option_key );
			if ( null === $rows ) {
				continue;
			}
			foreach ( $rows as $index => $row ) {
				if ( ! isset( $row->data[0] ) ) {
					continue;
				}
				$value = (string) $row->data[0];
				if ( '' === $value ) {
					continue;
				}
				$this->register_string( $this->option_string_name( $form_id, $field_id, $option_key, $index ), $value );
			}
		}
	}

	/* -----------------------------------------------------------------
	 * Bulk sync
	 * --------------------------------------------------------------- */

	/**
	 * Walk every WS Form form and re-register its strings. Cheap to
	 * run repeatedly — WPML treats `wpml_register_single_string` as
	 * upsert.
	 *
	 * @return int Number of forms processed.
	 */
	public function sync_all(): int {
		if ( ! class_exists( '\\WS_Form_Form' ) ) {
			return 0;
		}

		$lister = new \WS_Form_Form();

		// Bypass capability check — sync runs from CLI / admin-post
		// where we've already gated on edit_posts.
		$forms = $lister->db_read_all( '', '', '', '', '', false, true );
		if ( ! is_array( $forms ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $forms as $row ) {
			if ( ! is_object( $row ) || ! isset( $row->id ) ) {
				continue;
			}
			$form_id = (int) $row->id;
			if ( ! $this->is_form_allowed( $form_id ) ) {
				continue;
			}

			$reader     = new \WS_Form_Form();
			$reader->id = $form_id;
			try {
				// db_read( $get_meta, $get_groups, $checksum, $form_parse, $bypass_user_capability_check ).
				$form_object = $reader->db_read( true, true, false, false, true );
			} catch ( \Throwable $e ) {
				continue;
			}
			if ( ! is_object( $form_object ) ) {
				continue;
			}
			$this->register_form_strings( $form_object );
			++$count;
		}

		return $count;
	}

	/* -----------------------------------------------------------------
	 * Admin button
	 * --------------------------------------------------------------- */

	/**
	 * Capability required to trigger a sync. Mirrors WS Form's own
	 * `edit_form` cap so anyone who can edit a form can also rebuild
	 * its translation index — and nobody else can. Falls back to
	 * `manage_options` if WS Form's caps haven't been registered yet
	 * (e.g. mid-activation).
	 */
	private function sync_capability(): string {
		// `edit_form` is registered by WS Form on activation against
		// administrator/editor roles. If a site somehow lost the cap,
		// `manage_options` keeps the action accessible to admins.
		$cap = current_user_can( 'edit_form' ) ? 'edit_form' : 'manage_options';

		/**
		 * Filter the capability required to sync translations.
		 *
		 * @param string $cap Capability slug.
		 */
		return (string) apply_filters( 'wsform_wpml_helper_sync_capability', $cap );
	}

	/**
	 * Inject the sync button on the WS Form list screen — no separate
	 * menu page, just a notice-styled button where editors already are.
	 *
	 * @return void
	 */
	public function register_sync_menu(): void {
		add_action( 'in_admin_header', array( $this, 'maybe_render_sync_button' ) );
	}

	/**
	 * Render a "Sync translations" button on the WS Form list screen.
	 *
	 * @return void
	 */
	public function maybe_render_sync_button(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'toplevel_page_ws-form' !== $screen->id ) {
			return;
		}
		if ( ! current_user_can( $this->sync_capability() ) ) {
			return;
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=wsform_wpml_helper_sync' ),
			'wsform_wpml_helper_sync'
		);

		// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralDomain -- text domain set by caller.
		printf(
			'<div class="notice notice-info" style="margin:10px 20px;"><p>%1$s <a href="%2$s" class="button">%3$s</a></p></div>',
			esc_html( __( 'WS Form WPML helper:', $this->text_domain ) ),
			esc_url( $url ),
			esc_html( __( 'Sync translatable strings', $this->text_domain ) )
		);
		// phpcs:enable
	}

	/**
	 * Handle the sync admin-post request.
	 *
	 * @return void
	 */
	public function handle_sync_request(): void {
		if ( ! current_user_can( $this->sync_capability() ) ) {
			// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain -- text domain set by caller.
			wp_die( esc_html( __( 'You do not have permission to do this.', $this->text_domain ) ) );
		}
		check_admin_referer( 'wsform_wpml_helper_sync' );

		$count = $this->sync_all();

		$redirect = add_query_arg(
			array(
				'page'                       => 'ws-form',
				'wsform_wpml_helper_synced' => $count,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Render a one-shot success notice after sync.
	 *
	 * @return void
	 */
	public function maybe_render_sync_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag.
		if ( ! isset( $_GET['wsform_wpml_helper_synced'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag.
		$count = (int) wp_unslash( $_GET['wsform_wpml_helper_synced'] );

		// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralDomain -- text domain set by caller.
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
				/* translators: %d: number of forms processed. */
					_n(
						'%d WS Form processed — open WPML → String Translation to translate it.',
						'%d WS Forms processed — open WPML → String Translation to translate them.',
						$count,
						$this->text_domain
					),
					$count
				)
			)
		);
		// phpcs:enable
	}

	/* -----------------------------------------------------------------
	 * WP-CLI
	 * --------------------------------------------------------------- */

	/**
	 * `wp wsform-wpml-helper sync` — re-register every form.
	 *
	 * @param array<int, string>   $args       Positional args (subcommand).
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function cli( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$sub = $args[0] ?? '';
		if ( 'sync' !== $sub ) {
			\WP_CLI::error( "Unknown subcommand: '{$sub}'. Try 'sync'." );
			return;
		}

		$count = $this->sync_all();
		\WP_CLI::success( sprintf( 'Processed %d form(s). Open WPML → String Translation to translate.', $count ) );
	}

	/* -----------------------------------------------------------------
	 * Helpers
	 * --------------------------------------------------------------- */

	/**
	 * Iterate every field in a parsed form object, calling `$cb` on
	 * each. Tolerant of malformed structures.
	 *
	 * @param object   $form_object WS Form form object.
	 * @param callable $cb          Callable receiving each field.
	 */
	private function walk_fields( object $form_object, callable $cb ): void {
		if ( empty( $form_object->groups ) || ! is_array( $form_object->groups ) ) {
			return;
		}
		foreach ( $form_object->groups as $group ) {
			if ( empty( $group->sections ) || ! is_array( $group->sections ) ) {
				continue;
			}
			foreach ( $group->sections as $section ) {
				if ( empty( $section->fields ) || ! is_array( $section->fields ) ) {
					continue;
				}
				foreach ( $section->fields as $field ) {
					if ( ! is_object( $field ) ) {
						continue;
					}
					$cb( $field );
				}
			}
		}
	}

	/**
	 * Resolve the rows array for an option-bearing meta key, or null
	 * when the field doesn't carry options of that type.
	 *
	 * @param object $field      Field.
	 * @param string $option_key Meta key (e.g. 'data_grid_select').
	 * @return array<int, object>|null
	 */
	private function option_rows( object $field, string $option_key ): ?array {
		if (
			! isset( $field->meta->{$option_key} ) ||
			! is_object( $field->meta->{$option_key} ) ||
			! isset( $field->meta->{$option_key}->groups[0]->rows ) ||
			! is_array( $field->meta->{$option_key}->groups[0]->rows )
		) {
			return null;
		}
		return $field->meta->{$option_key}->groups[0]->rows;
	}

	/**
	 * Build the WPML string name (key) for a field property.
	 *
	 * Format: `form_{form_id}_field_{field_id}_{prop}`.
	 */
	private function string_name( int $form_id, int $field_id, string $prop ): string {
		return sprintf( 'form_%d_field_%d_%s', $form_id, $field_id, $prop );
	}

	/**
	 * Build the WPML string name for an option label.
	 *
	 * Format: `form_{form_id}_field_{field_id}_{option_key}_{index}`.
	 */
	private function option_string_name( int $form_id, int $field_id, string $option_key, int $index ): string {
		return sprintf( 'form_%d_field_%d_%s_%d', $form_id, $field_id, $option_key, $index );
	}

	/**
	 * Build the WPML string name for an action meta value.
	 *
	 * Format: `form_{form_id}_action_{row_index}_{meta_key}` — keyed
	 * by the action's position in the action list so two of the same
	 * action type (e.g. two Email notifications) translate
	 * independently. Reordering actions in the WS Form admin
	 * invalidates these keys; re-run the sync after reorders.
	 */
	private function action_string_name( int $form_id, int $row_index, string $meta_key ): string {
		return sprintf( 'form_%d_action_%d_%s', $form_id, $row_index, $meta_key );
	}

	/**
	 * Walk every action in `$form_object`, decode each row's JSON
	 * payload, and call `$cb($row_index, $action_id, $config)`.
	 *
	 * `row_index` (the action's position in the action list) is used
	 * as the key — not `row->id`, the auto-increment — because
	 * WS Form's runtime filter (`wsf_actions_post_{submit|save}`)
	 * only exposes `row_index` to action callbacks, so registration
	 * and translation must agree on it.
	 *
	 * Tolerant of malformed structures.
	 *
	 * @param object   $form_object WS Form form object.
	 * @param callable $cb          Receiver — `function(int $row_index, string $action_id, array $config): void`.
	 */
	private function walk_actions( object $form_object, callable $cb ): void {
		if (
			! isset( $form_object->meta ) ||
			! isset( $form_object->meta->action ) ||
			! isset( $form_object->meta->action->groups[0]->rows ) ||
			! is_array( $form_object->meta->action->groups[0]->rows )
		) {
			return;
		}

		foreach ( $form_object->meta->action->groups[0]->rows as $row_index => $row ) {
			if ( ! isset( $row->data[1] ) ) {
				continue;
			}
			if ( isset( $row->disabled ) && '' !== $row->disabled ) {
				continue;
			}
			$config = json_decode( (string) $row->data[1], true );
			if ( ! is_array( $config ) ) {
				continue;
			}
			$action_id = isset( $config['id'] ) ? (string) $config['id'] : '';
			if ( '' === $action_id ) {
				continue;
			}
			$cb( (int) $row_index, $action_id, $config );
		}
	}

	/**
	 * Resolve the translatable meta keys for a given action ID.
	 *
	 * Filter `wsform_wpml_helper_action_meta_keys` lets
	 * downstream projects extend the map (e.g. add custom-action
	 * coverage) without subclassing.
	 *
	 * @param string $action_id Action type slug.
	 * @return array<int, string>
	 */
	private function action_meta_keys( string $action_id ): array {
		$map = self::ACTION_META_KEYS;

		/**
		 * @param array<string, array<int, string>> $map
		 */
		$map = (array) apply_filters( 'wsform_wpml_helper_action_meta_keys', $map );

		if ( ! isset( $map[ $action_id ] ) || ! is_array( $map[ $action_id ] ) ) {
			return array();
		}
		return array_values( array_map( 'strval', $map[ $action_id ] ) );
	}

	/**
	 * Register one string with WPML String Translation. No-op when
	 * WPML is inactive.
	 */
	private function register_string( string $name, string $value ): void {
		do_action( 'wpml_register_single_string', $this->context, $name, $value );
	}

	/**
	 * Translate one string via WPML. Falls back to the original value
	 * when WPML is inactive or the string is untranslated.
	 */
	private function translate( string $name, string $original ): string {
		$translated = apply_filters( 'wpml_translate_single_string', $original, $this->context, $name );
		return is_string( $translated ) && '' !== $translated ? $translated : $original;
	}
}
