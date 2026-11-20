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
 * 所以這裡掛 `wp_register_ability_args`。只做一件事：把清單型的 `type` 塌成單一型別。
 * 不碰 enum、required、description，也不動任何非 `type` 的鍵。1.3.10 起只動 Moksa 自家
 * 命名空間的 input_schema；別人的 ability 與所有 output_schema 原封不動。
 *
 * @package Moksa\AI
 */

declare( strict_types=1 );

namespace Moksa\AI;

defined( 'ABSPATH' ) || exit;

final class SchemaCompat {

	/**
	 * 已經回報過的 ability，避免同一個 request 洗版.
	 *
	 * @var array<string,true>
	 */
	private static array $reported = array();

	/**
	 * 這個 request 裡被塌過型別、還沒決定要不要回報的 ability（名稱 => 處數）.
	 *
	 * @var array<string,int>
	 */
	private static array $pending = array();

	public static function init(): void {
		// 20 = 比外掛自己可能掛的 filter 晚，收到的是最終形狀。
		add_filter( 'wp_register_ability_args', array( self::class, 'compat_args' ), 20, 2 );
		// 提示只給開發者看，所以只在 WP_DEBUG 下才排；正式站不多花任何成本.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			add_action( 'wp_loaded', array( self::class, 'flush_reports' ), 99 );
		}
	}

	/**
	 * @param array<string,mixed> $args 註冊參數。
	 * @param string              $name Ability 名稱。
	 * @return array<string,mixed>
	 */
	public static function compat_args( $args, $name ): array {
		if ( ! is_array( $args ) || ! self::applies( (string) $name, $args ) ) {
			return $args;
		}
		$hits = 0;
		// 只收斂 input_schema：核心交給模型的只有它（using_abilities() 只送 get_input_schema()）。
		// output_schema 是 WP 執行時驗證回傳用的，改了就跟實際回傳對不上（1.3.10，Novamira 事件）.
		if ( isset( $args['input_schema'] ) && is_array( $args['input_schema'] ) ) {
			$args['input_schema'] = self::collapse( $args['input_schema'], $hits );
		}
		if ( $hits > 0 ) {
			self::$pending[ (string) $name ] = $hits;
		}
		return $args;
	}

	/**
	 * 只處理 Moksa 外掛自己的 ability（依名稱的命名空間）。
	 *
	 * 別人的 ability 不會交給 Moksa AI，改它們的宣告只會讓 WP 的驗證跟實際回傳對不上
	 * （Novamira execute-ability 的多型別 output 被改成 string，FluentCRM 回物件就失敗）。
	 * 註冊途中不能讀 Registry（會觸發 Abilities API 的延遲註冊），所以用命名空間判斷。
	 *
	 * @param string              $name Ability 名稱。
	 * @param array<string,mixed> $args 註冊參數。
	 */
	private static function applies( string $name, array $args ): bool {
		/**
		 * 要收斂 input_schema 的 ability 命名空間（各外掛實際註冊的名稱，11-18 掃過）.
		 * 把別的外掛的 ability 加進 Moksa AI 工具清單時，也要把它的命名空間加進來，
		 * 否則它的聯集型別會讓 Gemini 把整包工具退回。寫 'points' 或 'points/' 都可以.
		 *
		 * @param string[] $namespaces 命名空間清單。
		 */
		$namespaces = (array) apply_filters(
			'moksa_ai_schema_compat_namespaces',
			array( 'moksa-for-woocommerce', 'moksafocou', 'moksafoboo', 'points', 'member', 'affiliate', 'groupbuy' )
		);
		$namespaces = array_map( static fn( $n ): string => rtrim( (string) $n, '/' ), $namespaces );

		$ns      = strstr( $name, '/', true );
		$applies = false !== $ns && '' !== $ns && in_array( $ns, $namespaces, true );
		/**
		 * 個別決定某個 ability 要不要收斂 input_schema（加入或排除）.
		 *
		 * @param bool                $applies 是否收斂。
		 * @param string              $name    Ability 名稱。
		 * @param array<string,mixed> $args    註冊參數。
		 */
		return (bool) apply_filters( 'moksa_ai_schema_compat_applies', $applies, $name, $args );
	}

	/**
	 * 只對「真的交給 Moksa AI 當工具」的 ability 發提示.
	 *
	 * 自家命名空間下沒交給 Moksa AI 的 ability 也會被收斂 input，但對它們發 notice 只是洗版
	 * （別人的 ability 1.3.10 起完全不動）。不能在註冊當下判斷：組工具清單的 filter 會去讀 Abilities API，註冊途中讀
	 * 會出錯；而且 Abilities API 是延遲註冊的 —— 這裡先讀清單，順便讓註冊跑完、pending 填滿.
	 */
	public static function flush_reports(): void {
		$ours = Registry::abilities();
		foreach ( self::$pending as $name => $hits ) {
			if ( isset( self::$reported[ $name ] ) || ! in_array( $name, $ours, true ) ) {
				continue;
			}
			self::$reported[ $name ] = true;
			self::report( $name, $hits );
		}
		self::$pending = array();
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
	 * 用 WP 6.4 起的 wp_trigger_error()：它本身只在 WP_DEBUG 開啟時觸發，
	 * 不必用 error_log() 再靠 PHPCS 忽略註解消警告。舊版 WP 沒有這個函式就不出聲。
	 */
	private static function report( string $name, int $hits ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG || ! function_exists( 'wp_trigger_error' ) ) {
			return;
		}
		wp_trigger_error(
			__METHOD__,
			sprintf(
				'Moksa AI: ability "%s" 的 schema 有 %d 處用了陣列型的 "type"（例如 ["number","null"]）。'
				. 'Gemini 不接受，已自動塌成單一型別。請改成單一 type，或另外提供給 AI 用的 schema。',
				$name,
				$hits
			),
			E_USER_NOTICE
		);
	}
}
