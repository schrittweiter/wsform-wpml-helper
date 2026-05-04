<?php
/**
 * Plugin Name:       WS Form WPML Helper
 * Plugin URI:        https://schrittweiter.de
 * Description:       Makes WS Form forms translatable via WPML String Translation — no per-language form duplication required. Auto-discovers labels, placeholders, help text, validation messages, button labels, option labels and action strings (success message, email subject/body).
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            schrittweiter GmbH
 * Author URI:        https://schrittweiter.de
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wsform-wpml-helper
 * Domain Path:       /languages
 *
 * @package WSForm_WPML_Helper
 */

defined( 'ABSPATH' ) || exit;

define( 'WSFORM_WPML_HELPER_VERSION', '1.0.0' );
define( 'WSFORM_WPML_HELPER_FILE', __FILE__ );
define( 'WSFORM_WPML_HELPER_DIR', plugin_dir_path( __FILE__ ) );

require_once WSFORM_WPML_HELPER_DIR . 'includes/class-wsform-translator.php';

/**
 * Boot the translator once all plugins are loaded so we can detect
 * WS Form and WPML reliably.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		load_plugin_textdomain(
			'wsform-wpml-helper',
			false,
			dirname( plugin_basename( WSFORM_WPML_HELPER_FILE ) ) . '/languages'
		);

		$missing = wsform_wpml_helper_missing_dependencies();
		if ( ! empty( $missing ) ) {
			add_action(
				'admin_notices',
				static function () use ( $missing ): void {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}
					printf(
						'<div class="notice notice-warning"><p><strong>%s</strong> %s %s</p></div>',
						esc_html__( 'WS Form WPML Helper:', 'wsform-wpml-helper' ),
						esc_html__( 'inactive — missing dependency:', 'wsform-wpml-helper' ),
						esc_html( implode( ', ', $missing ) )
					);
				}
			);
			return;
		}

		( new \WSForm_WPML_Helper\WSForm_Translator(
			'WS Form WPML Helper',
			'wsform-wpml-helper'
		) )->register();
	}
);

/**
 * Return a list of human-readable names of missing dependencies.
 *
 * WS Form is detected via its main class. WPML String Translation is
 * detected via the `wpml_register_single_string` action — it's the
 * only public surface we need from WPML, so checking for the hook
 * itself is sufficient and works with both WPML core and the standalone
 * String Translation add-on configurations.
 *
 * @return array<int, string>
 */
function wsform_wpml_helper_missing_dependencies(): array {
	$missing = array();

	if ( ! class_exists( '\\WS_Form' ) && ! class_exists( '\\WS_Form_Form' ) ) {
		$missing[] = __( 'WS Form', 'wsform-wpml-helper' );
	}

	if ( ! has_action( 'wpml_register_single_string' ) && ! has_filter( 'wpml_translate_single_string' ) ) {
		$missing[] = __( 'WPML String Translation', 'wsform-wpml-helper' );
	}

	return $missing;
}
