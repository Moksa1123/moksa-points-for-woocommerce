<?php
/**
 * Moksa AI Registry — 共用層跟各外掛之間唯一的接縫。
 *
 * 每個 Moksa 外掛都 bundle 一份共用層，但只有選舉勝出的那份會執行；
 * 各外掛不論輸贏，都透過下面這幾個 filter 把自己的能力推進來。
 * 所以 filter 名稱一律中立（moksa_ai_*），不帶任何單一外掛的前綴 ——
 * 否則就變成「大家依賴某一個外掛」，而那個外掛可能根本沒被安裝。
 *
 * @package Moksa\AI
 */

declare( strict_types=1 );

namespace Moksa\AI;

defined( 'ABSPATH' ) || exit;

final class Registry {

	/**
	 * 暴露給模型當工具的 ability 名稱（含破壞性的 —— 那些會被 Agent 攔下走確認關卡）。
	 *
	 * @return string[]
	 */
	public static function abilities(): array {
		$abilities = (array) apply_filters( 'moksa_ai_abilities', array() );
		$clean     = array();
		$can_check = function_exists( 'wp_get_ability' );

		foreach ( $abilities as $name ) {
			$name = (string) $name;
			if ( '' === $name || in_array( $name, $clean, true ) ) {
				continue;
			}
			// 只暴露「真的註冊在 Abilities API 裡」的能力。外掛常常寫死一份名單，
			// 但實際註冊那些能力的模組可能是關的 —— 把不存在的工具名交給模型，
			// 它不會報錯，而是照著工具名編一個答案出來。
			if ( $can_check && ! wp_get_ability( $name ) ) {
				continue;
			}
			$clean[] = $name;
		}
		return $clean;
	}

	/**
	 * 破壞性動作處理表：ability 名稱 => [ prepare 驗證並產生摘要, apply 真正執行 ]。
	 *
	 * @return array<string, array{prepare:callable, apply:callable}>
	 */
	public static function destructive_handlers(): array {
		$handlers = (array) apply_filters( 'moksa_ai_destructive_handlers', array() );
		$clean    = array();
		foreach ( $handlers as $ability => $pair ) {
			if ( ! is_array( $pair ) ) {
				continue;
			}
			if ( ! isset( $pair['prepare'], $pair['apply'] ) ) {
				continue;
			}
			if ( ! is_callable( $pair['prepare'] ) || ! is_callable( $pair['apply'] ) ) {
				continue;
			}
			$clean[ (string) $ability ] = $pair;
		}
		return $clean;
	}

	/** @return string[] */
	public static function destructive_abilities(): array {
		return array_keys( self::destructive_handlers() );
	}

	/**
	 * 系統提示。各外掛用 filter 追加自己領域的規則；共用層只給一句通用的骨架。
	 */
	public static function system_instruction(): string {
		$base = 'You are Moksa AI, an assistant embedded in a WooCommerce store admin. '
			. 'Always look up real data with the tools before answering, then reply briefly and clearly '
			. 'in the language the merchant is using. Never invent an order, amount, date or number — '
			. 'if the tools find nothing, say so plainly. Actions that change data are proposed to the '
			. 'merchant for confirmation by the system, so never ask for confirmation yourself. '
			. 'Always finish with a text answer; never stop at a tool call.';

		return (string) apply_filters( 'moksa_ai_system_instruction', $base );
	}

	/**
	 * 執行 AI 助手需要的權限。預設 manage_woocommerce；外掛可收緊。
	 */
	public static function capability(): string {
		$cap = (string) apply_filters( 'moksa_ai_capability', 'manage_woocommerce' );
		return '' !== $cap ? $cap : 'manage_woocommerce';
	}

	/**
	 * 介面字串。共用層是 bundle 進各外掛的函式庫，沒有自己的 textdomain，
	 * 所以字串由使用它的外掛翻好了餵進來；沒人餵就用英文。
	 *
	 * @return array<string, string>
	 */
	public static function strings(): array {
		$defaults = array(
			'name'         => 'Moksa AI',
			'greeting'     => 'Hi, I am Moksa AI. Ask me about your orders, shipments or invoices.',
			'placeholder'  => 'For example: how many orders are awaiting shipment?',
			'send'         => 'Send',
			'thinking'     => 'Looking that up',
			'clear'        => 'Clear',
			'errorPrefix'  => 'Something went wrong',
			'emptyReply'   => '(no reply)',
			'confirmRun'   => 'Confirm',
			'confirmSkip'  => 'Cancel',
			'unavailable'  => 'The AI Client is unavailable. It requires WordPress 7.0.',
			'busy'         => 'The AI cannot answer right now. Please try again shortly.',
			'exhausted'    => 'The AI could not finish after several attempts. Try asking a different way.',
			'unsupported'  => 'Unsupported action.',
			'notPrepared'  => 'This action could not be prepared.',
			'confirmStale' => 'That confirmation has expired or no longer exists. Please start again.',
			'noPermission' => 'You do not have permission to do this.',
			'examples'     => array(),
		);

		$strings = (array) apply_filters( 'moksa_ai_strings', $defaults );
		return array_merge( $defaults, $strings );
	}

	public static function string( string $key ): string {
		$all = self::strings();
		return isset( $all[ $key ] ) && is_string( $all[ $key ] ) ? $all[ $key ] : '';
	}

	/**
	 * 有沒有東西可跑 —— 沒有任何外掛註冊 ability 時，共用層退回無 LLM 的命令選盤。
	 */
	public static function has_agent(): bool {
		return ! self::host_suppressed()
			&& function_exists( 'wp_ai_client_prompt' )
			&& array() !== self::abilities();
	}

	/**
	 * 讓尚未遷移、還在畫自己那個窗的外掛把共用窗壓下去，避免過渡期出現兩個窗。
	 *
	 * 遷移完成後這個 filter 就不該再有人掛 —— 它是過渡機制，不是長期設計。
	 */
	public static function host_suppressed(): bool {
		return (bool) apply_filters( 'moksa_ai_suppress_host', false );
	}
}
