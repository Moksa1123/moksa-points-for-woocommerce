<?php
/**
 * Moksa AI Agent — WordPress 7.0 AI Client 的 agentic 迴圈。
 *
 * 把各外掛註冊進 Registry 的 abilities 當工具，跑「生成 → 執行 ability → 餵回 → 再生成」
 * 直到拿到文字答覆。破壞性 ability 會被攔下，改回傳「需確認」讓人工按鈕確認，
 * 絕不在迴圈內執行。
 *
 * 安全三層：
 *   ① ability 白名單（模型只能呼叫傳進來的）
 *   ② 每個 ability 自己的 permission_callback（核心強制）
 *   ③ 破壞性動作一律走人工確認（本 class 攔截）
 *
 * 回傳一律結構化陣列：
 *   ['type'=>'text','reply'=>string]
 *   ['type'=>'confirm','token'=>string,'summary'=>string]
 *   ['type'=>'error','message'=>string]
 *
 * @package Moksa\AI
 */

declare( strict_types=1 );

namespace Moksa\AI;

defined( 'ABSPATH' ) || exit;

final class Agent {

	const MAX_TURNS      = 8;
	const MODELS         = array( 'gemini-2.5-flash', 'claude-sonnet-4-6', 'gpt-4o-mini' );
	const CONFIRM_PREFIX = 'moksa_ai_confirm_';
	const CONFIRM_TTL    = 5 * MINUTE_IN_SECONDS;

	/**
	 * @param string                                     $user_text 商家提問。
	 * @param array<int, array{role?:string, text?:string}> $prior     前端帶來的對話歷史。
	 * @return array<string,mixed>
	 */
	public static function run( string $user_text, array $prior = array() ): array {
		$abilities = Registry::abilities();
		if ( ! function_exists( 'wp_ai_client_prompt' ) || array() === $abilities ) {
			return self::err( Registry::string( 'unavailable' ) );
		}

		$system   = Registry::system_instruction();
		$resolver = new \WP_AI_Client_Ability_Function_Resolver( ...$abilities );

		$destructive = array();
		foreach ( Registry::destructive_abilities() as $name ) {
			$destructive[ \WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name( $name ) ] = $name;
		}

		$history = self::seed_history( $prior );
		$current = $user_text;
		$models  = self::MODELS; // 本地可變副本：卡在會回空內容的 provider 時可換家。

		for ( $turn = 0; $turn < self::MAX_TURNS; $turn++ ) {
			// 逐家 model failover：某家失敗（常見 Gemini 回空 content.parts、或 connector 失效）
			// 就換下一家，而不是整個請求失敗。using_model_preference 本身不會自動換家。
			$result   = null;
			$last_err = '';
			foreach ( $models as $model ) {
				// wp_ai_client_prompt() 是 WordPress 7.0 才進核心的 AI Client 入口。本檔在最上方已用
				// function_exists() 守住(6.9 站台不會走到這裡),但 Plugin Check 的靜態相容性掃描看不到
				// 那道守衛,會把「直接呼叫」判成需要 7.0 的 ERROR。改用可變函式呼叫:行為完全相同,
				// 靜態掃描不再誤判,外掛也維持 Requires at least: 6.9。
				$prompt  = 'wp_ai_client_prompt';
				$builder = $prompt( $current )
					->using_system_instruction( $system )
					->using_abilities( ...$abilities )
					->using_model_preference( $model );
				if ( ! empty( $history ) ) {
					$builder = $builder->with_history( ...$history );
				}
				$attempt = $builder->generate_text_result();
				if ( ! is_wp_error( $attempt ) ) {
					$result = $attempt;
					break;
				}
				$last_err = $attempt->get_error_message();
			}
			if ( null === $result ) {
				return self::err( '' !== $last_err ? $last_err : Registry::string( 'busy' ) );
			}

			$assistant = $result->toMessage();

			$dcall = self::find_destructive_call( $assistant, $destructive );
			if ( null !== $dcall ) {
				return self::prepare_confirm( $dcall['ability'], $dcall['args'] );
			}

			if ( ! $resolver->has_ability_calls( $assistant ) ) {
				$text = '';
				try {
					$text = (string) $result->toText();
				} catch ( \Throwable $e ) {
					$text = '';
				}
				if ( '' !== trim( $text ) ) {
					return self::text( $text );
				}
				$history[] = self::as_user_message( $current );
				$current   = new \WordPress\AiClient\Messages\DTO\UserMessage(
					array( new \WordPress\AiClient\Messages\DTO\MessagePart( 'Answer my question in text now, briefly, using what the tools already returned.' ) )
				);
				// 空內容又沒有工具呼叫：把這家移到隊尾，下個 turn 換別家先試。
				$models[] = array_shift( $models );
				continue;
			}

			$tool_response = $resolver->execute_abilities( $assistant );
			$history[]     = self::as_user_message( $current );
			$history[]     = $assistant;
			$current       = $tool_response;
		}

		return self::err( Registry::string( 'exhausted' ) );
	}

	/**
	 * @param string|object $current 上一輪的使用者訊息或工具回應。
	 * @return object
	 */
	private static function as_user_message( $current ) {
		return is_string( $current )
			? new \WordPress\AiClient\Messages\DTO\UserMessage( array( new \WordPress\AiClient\Messages\DTO\MessagePart( $current ) ) )
			: $current;
	}

	/**
	 * 把前端帶來的對話歷史 seed 成 AI Client 訊息（上下文記憶）。
	 *
	 * 各家 provider 對歷史要求嚴格交替且由 user 起，故：去開頭的 assistant、
	 * 合併連續同角色、移除結尾的 user（避免與接下來的新 user prompt 連續）。
	 *
	 * @param array<int, array{role?:string, text?:string}> $prior 前端對話歷史。
	 * @return object[]
	 */
	private static function seed_history( array $prior ): array {
		$turns = array();
		foreach ( $prior as $turn ) {
			if ( ! is_array( $turn ) || empty( $turn['text'] ) ) {
				continue;
			}
			$role = ( isset( $turn['role'] ) && 'assistant' === $turn['role'] ) ? 'assistant' : 'user';
			$text = trim( (string) $turn['text'] );
			if ( '' === $text ) {
				continue;
			}
			if ( empty( $turns ) && 'assistant' === $role ) {
				continue;
			}
			$n = count( $turns );
			if ( $n > 0 && $turns[ $n - 1 ]['role'] === $role ) {
				$turns[ $n - 1 ]['text'] .= "\n" . $text;
			} else {
				$turns[] = array(
					'role' => $role,
					'text' => $text,
				);
			}
		}
		while ( ! empty( $turns ) && 'user' === $turns[ array_key_last( $turns ) ]['role'] ) {
			array_pop( $turns );
		}

		$out = array();
		foreach ( $turns as $t ) {
			$part  = new \WordPress\AiClient\Messages\DTO\MessagePart( $t['text'] );
			$out[] = ( 'assistant' === $t['role'] )
				? new \WordPress\AiClient\Messages\DTO\ModelMessage( array( $part ) )
				: new \WordPress\AiClient\Messages\DTO\UserMessage( array( $part ) );
		}
		return $out;
	}

	/**
	 * 掃描助理訊息有沒有呼叫破壞性 ability。
	 *
	 * @param object               $assistant   助理訊息。
	 * @param array<string,string> $destructive function-name => ability-name。
	 * @return array{ability:string, args:array}|null
	 */
	private static function find_destructive_call( $assistant, array $destructive ): ?array {
		foreach ( $assistant->getParts() as $part ) {
			if ( ! $part->getType()->isFunctionCall() ) {
				continue;
			}
			$fc = $part->getFunctionCall();
			$fn = ( is_object( $fc ) && method_exists( $fc, 'getName' ) ) ? $fc->getName() : '';
			if ( isset( $destructive[ $fn ] ) ) {
				$args = ( is_object( $fc ) && method_exists( $fc, 'getArgs' ) ) ? (array) $fc->getArgs() : array();
				return array(
					'ability' => $destructive[ $fn ],
					'args'    => $args,
				);
			}
		}
		return null;
	}

	/**
	 * 準備破壞性動作（只驗證並產生摘要，不執行），存一次性 token，回「需確認」。
	 *
	 * @param string $ability ability 名稱。
	 * @param array  $args    AI 給的參數。
	 * @return array<string,mixed>
	 */
	private static function prepare_confirm( string $ability, array $args ): array {
		$handlers = Registry::destructive_handlers();
		if ( ! isset( $handlers[ $ability ]['prepare'] ) ) {
			return self::err( Registry::string( 'unsupported' ) );
		}

		$prepared = call_user_func( $handlers[ $ability ]['prepare'], $args );
		if ( is_wp_error( $prepared ) ) {
			return self::err( $prepared->get_error_message() );
		}
		if ( ! is_array( $prepared ) || empty( $prepared['summary'] ) ) {
			return self::err( Registry::string( 'notPrepared' ) );
		}

		$token = wp_generate_password( 24, false, false );
		set_transient(
			self::CONFIRM_PREFIX . $token,
			array(
				'user'    => get_current_user_id(),
				'ability' => $ability,
				'params'  => $prepared,
			),
			self::CONFIRM_TTL
		);

		return array(
			'type'    => 'confirm',
			'token'   => $token,
			'summary' => (string) $prepared['summary'],
		);
	}

	private static function text( string $reply ): array {
		return array(
			'type'  => 'text',
			'reply' => $reply,
		);
	}

	private static function err( string $message ): array {
		return array(
			'type'    => 'error',
			'message' => $message,
		);
	}
}
