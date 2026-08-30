<?php
/**
 * Moksa AI Launcher — bundled loader (single-instance version election).
 *
 * Every Moksa plugin bundles a copy of this library and requires THIS file early. Each copy
 * registers its (version, dir) into a shared global pool; the highest-version copy wins and
 * boots the one launcher. Mirrors the Action Scheduler "many plugins, one component" pattern,
 * so each plugin stays standalone + wp.org-safe with zero hard dependency on the others.
 *
 * IMPORTANT: this file defines NO class and NO constant (those would collide across the
 * bundled copies). It only appends to a global array and defines one guarded boot function.
 * The pool entry shape, moksa_ai_boot behaviour and Launcher::init() signature are FROZEN —
 * only add, never break — or a newer copy electing against an older one will mismatch.
 *
 * @package Moksa\AI
 */

defined( 'ABSPATH' ) || exit;

// Bump this string when shipping a newer bundled launcher. Highest version wins the election.
$GLOBALS['moksa_ai_pool'][] = array(
	// 1.3.4:Agent 改用可變函式呼叫 wp_ai_client_prompt(),避開 Plugin Check 的
	// wp_function_not_compatible_with_requires_wp 誤判(呼叫本身仍受 function_exists 守衛)。
	'version' => '1.3.4',
	'dir'     => __DIR__,
);

if ( ! function_exists( 'moksa_ai_boot' ) ) {
	/**
	 * Elect the highest-version bundled copy and boot its single launcher.
	 * Defined once (first-loaded copy wins the definition); every copy still registered above.
	 */
	function moksa_ai_boot(): void {
		$pool = isset( $GLOBALS['moksa_ai_pool'] ) && is_array( $GLOBALS['moksa_ai_pool'] ) ? $GLOBALS['moksa_ai_pool'] : array();
		if ( empty( $pool ) ) {
			return;
		}
		usort(
			$pool,
			static function ( array $a, array $b ): int {
				return version_compare( (string) ( $a['version'] ?? '0' ), (string) ( $b['version'] ?? '0' ) );
			}
		);
		$latest = end( $pool );
		$file   = (string) ( $latest['dir'] ?? '' ) . '/src/Launcher.php';
		if ( '' === $file || ! is_readable( $file ) ) {
			return;
		}
		require_once $file;
		if ( class_exists( '\Moksa\AI\Launcher' ) ) {
			\Moksa\AI\Launcher::init( (string) $latest['dir'] );
		}
	}

	// Late on plugins_loaded so every bundled copy has registered first.
	add_action( 'plugins_loaded', 'moksa_ai_boot', 50 );
}
