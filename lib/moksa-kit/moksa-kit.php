<?php
/**
 * Moksa Kit — bundled loader (single-instance version election).
 *
 * The platform's five plugins had been carrying byte-identical copies of the same infrastructure:
 * the `mowp-` settings design system, the settings audit ring buffer, the settings export/import
 * porter. Each copy drifted on its own schedule, so a fix in one never reached the other four.
 * This library is that shared layer, bundled exactly the way {@see moksa-ai.php} is bundled: every
 * plugin ships a full copy (wp.org plugins must stand alone), and at runtime the highest-version
 * copy wins one election and serves every consumer in the request.
 *
 * IMPORTANT: this file defines NO class and NO constant (those would collide across the bundled
 * copies). It only appends to a global array and defines guarded functions. The pool entry shape and
 * the `\Moksa\Kit\*` public API are FROZEN — only add, never break — because a NEWER copy will be
 * asked to serve an OLDER consumer that was written against an earlier version.
 *
 * The kit deliberately contains no translatable strings: a bundled library cannot own a textdomain
 * (each plugin has its own, and a dynamic domain cannot be extracted into a .pot). Anything a human
 * reads stays in the consuming plugin — the kit returns codes and markup, never sentences.
 *
 * @package Moksa\Kit
 */

defined( 'ABSPATH' ) || exit;

// Bump this string when shipping a newer bundled kit. Highest version wins the election.
$GLOBALS['moksa_kit_pool'][] = array(
	// 1.0.0:SettingsUi(設計系統 CSS/JS)、SettingsAudit(設定變更環形記錄)、
	//       SettingsPorter(設定匯出 / 匯入)三項從五支外掛抽出。
	'version' => '1.0.0',
	'dir'     => __DIR__,
);

if ( ! function_exists( 'moksa_kit_dir' ) ) {
	/**
	 * The directory of the highest-version bundled copy.
	 *
	 * Re-elected on every call rather than memoised once: a copy registered by a plugin that loads
	 * later must still be able to win, and the pool is at most a handful of entries.
	 */
	function moksa_kit_dir(): string {
		$pool = isset( $GLOBALS['moksa_kit_pool'] ) && is_array( $GLOBALS['moksa_kit_pool'] ) ? $GLOBALS['moksa_kit_pool'] : array();
		if ( empty( $pool ) ) {
			return '';
		}
		$best     = '';
		$best_ver = '';
		foreach ( $pool as $entry ) {
			$ver = (string) ( $entry['version'] ?? '0' );
			$dir = (string) ( $entry['dir'] ?? '' );
			if ( '' === $dir ) {
				continue;
			}
			if ( '' === $best || version_compare( $ver, $best_ver, '>' ) ) {
				$best     = $dir;
				$best_ver = $ver;
			}
		}
		return $best;
	}

	/**
	 * Autoload `\Moksa\Kit\*` from whichever bundled copy wins the election.
	 *
	 * An autoloader rather than an eager require: the kit has no boot moment, its classes are used at
	 * very different points in a request (admin_enqueue_scripts, a settings save, an import), and a
	 * consumer must never have to care whether some other plugin has loaded yet.
	 *
	 * @param string $class Fully-qualified class name being autoloaded.
	 */
	function moksa_kit_autoload( string $class ): void {
		if ( 0 !== strpos( $class, 'Moksa\\Kit\\' ) ) {
			return;
		}
		$dir = moksa_kit_dir();
		if ( '' === $dir ) {
			return;
		}
		$relative = str_replace( '\\', '/', substr( $class, strlen( 'Moksa\\Kit\\' ) ) );
		// Defensive: never let a crafted class name walk out of the kit directory.
		if ( '' === $relative || false !== strpos( $relative, '..' ) ) {
			return;
		}
		$file = $dir . '/src/' . $relative . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}

	spl_autoload_register( 'moksa_kit_autoload' );
}
