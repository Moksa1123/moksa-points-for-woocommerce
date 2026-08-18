<?php
/**
 * Moksa AI SchemaCompat — 把 ability schema 收成「所有模型供應商都吃得下」的形狀。
 *
 * 為什麼需要這層：JSON Schema 允許 `"type": ["number","null"]` 這種聯集型別，REST 那邊
 * 完全合法。但 Gemini 的 Schema proto 裡 `type` 是單一 enum，收到陣列會回
 * `Proto field is not repeating, cannot start list` 400 —— 而且是**整包 function
 * declarations 一起退**。也就是說任何一個外掛的 schema 寫了聯集型別，七個外掛的工具
 * 就全部不能用，對話窗連一次工具都呼叫不到。2026-08-10 實際發生過（優惠券外掛的
 * create/update/bulk-generate 帶了 30 個 `["number","null"]`，連查訂單都跟著掛）。
 *
 * 所以這裡掛 `wp_register_ability_args` —— 那是每個 ability 註冊時的必經之路，不分外掛。
 * 只做一件事：把清單型的 `type` 塌成單一型別。不碰 enum、required、description，
 * 也不動任何非 `type` 的鍵，避免把別人的 schema 改出別的問題。
 *
 * @package Moksa\AI
 */

declare( strict_types=1 );

namespace Moksa\AI;

defined( 'ABSPATH' ) || exit;

final class SchemaCompat {

	/** 已經回報過的 ability，避免同一個 request 洗版。 @var array<string,true> */
	private static array $reported = array();

	public static function init(): void {
		// 20 = 比外掛自己可能掛的 filter 晚，收到的是最終形狀。
		add_filter( 'wp_register_ability_args', array( self::class, 'compat_args' ), 20, 2 );
	}

	/**
	 * @param array<string,mixed> $args 註冊參數。
	 * @param string              $name Ability 名稱。
	 * @return array<string,mixed>
	 */
	public static function compat_args( $args, $name ): array {
		if ( ! is_array( $args ) ) {
			return $args;
		}
		$hits = 0;
		foreach ( array( 'input_schema', 'output_schema' ) as $key ) {
			if ( isset( $args[ $key ] ) && is_array( $args[ $key ] ) ) {
				$args[ $key ] = self::collapse( $args[ $key ], $hits );
			}
		}
		if ( $hits > 0 && ! isset( self::$reported[ (string) $name ] ) ) {
			self::$reported[ (string) $name ] = true;
			self::report( (string) $name, $hits );
		}
		return $args;
	}

	/**
	 * 遞迴把 `type` 的陣列值塌成單一字串。
	 *
	 * @param array<mixed> $node  Schema 節點。
	 * @param int          $hits  改了幾處（by reference）。
	 * @return array<mixed>
	 */
	private static function collapse( array $node, int &$hits ): array {
		if ( isset( $node['type'] ) && is_array( $node['type'] ) && array_is_list( $node['type'] ) ) {
			$single = self::pick( $node['type'] );
			if ( null !== $single ) {
				$node['type'] = $single;
				++$hits;
			}
		}

		foreach ( $node as $k => $v ) {
			if ( is_array( $v ) ) {
				$node[ $k ] = self::collapse( $v, $hits );
			}
		}
		return $node;
	}

	/**
	 * 從聯集裡挑一個型別。
	 *
	 * `null` 一律先丟掉 —— 它在 JSON Schema 裡表達的是「可清空」，不是一個模型要產生的
	 * 型別。剩一個就用那個；剩多個代表這個欄位本來就多型（例如規則樹的 value 可以是
	 * 數字、陣列或物件），這種情況挑 `string`：模型永遠產得出字串，而會宣告多型欄位的
	 * handler 本來就得自己解析。挑不出來就原封不動，寧可不改也不要改壞。
	 *
	 * @param array<mixed> $types 型別清單。
	 */
	private static function pick( array $types ): ?string {
		$types = array_values(
			array_filter(
				array_map( 'strval', $types ),
				static function ( string $t ): bool {
					return '' !== $t && 'null' !== $t;
				}
			)
		);
		if ( array() === $types ) {
			return null;
		}
		if ( 1 === count( $types ) ) {
			return $types[0];
		}
		return in_array( 'string', $types, true ) ? 'string' : $types[0];
	}

	/**
	 * 讓寫壞的外掛作者看得到。只在 WP_DEBUG 下出聲，正式站不吵。
	 */
	private static function report( string $name, int $hits ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG 下的開發者提示。
			sprintf(
				'Moksa AI: ability "%s" 的 schema 有 %d 處用了陣列型的 "type"（例如 ["number","null"]）。'
				. 'Gemini 不接受，已自動塌成單一型別。請改成單一 type，或另外提供給 AI 用的 schema。',
				$name,
				$hits
			)
		);
	}
}
