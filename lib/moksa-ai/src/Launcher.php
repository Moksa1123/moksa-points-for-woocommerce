<?php
/**
 * Moksa AI Launcher — 選舉勝出那份副本的進入點。
 *
 * `moksa-ai.php` 的 `moksa_ai_boot()` 只認得兩件事：這個檔案的路徑，以及
 * `Launcher::init( $dir )` 這個簽章。**兩者都 FROZEN** —— 因為定義 `moksa_ai_boot()`
 * 的是「第一個被載入的副本」（可能是舊版），而它 require 的是「版本最高的副本」的
 * 這個檔案。新舊就在這裡交界，所以新功能一律掛在 init() 裡，不要動 moksa-ai.php。
 *
 * @package Moksa\AI
 */

declare( strict_types=1 );

namespace Moksa\AI;

defined( 'ABSPATH' ) || exit;

final class Launcher {

	private static bool $booted = false;

	/** $dir = 選舉勝出那份副本的目錄（資源都在它底下）。 */
	public static function init( string $dir ): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		require_once __DIR__ . '/Registry.php';
		require_once __DIR__ . '/Agent.php';
		require_once __DIR__ . '/Host.php';
		require_once __DIR__ . '/SchemaCompat.php';

		// 要在任何 ability 註冊之前掛上（註冊發生在 init 之後，這裡是 plugins_loaded:50）。
		// 晚了的話就攔不到，整包工具會被 Gemini 退掉。
		SchemaCompat::init();

		Host::init( $dir );
	}
}
