<?php
/**
 * Moksa AI Host — 全站唯一的 AI 對話窗（REST + 浮動視窗）。
 *
 * 由 Launcher::init() 在偵測到有外掛註冊 abilities 時啟動。沒有任何外掛註冊、
 * 或站上沒有 WordPress 7.0 的 AI Client 時不啟動，改由 Launcher 的命令選盤接手。
 *
 * REST 命名空間刻意用中立的 moksa-ai/v1 —— 端點屬於共用層，不屬於任何單一外掛。
 *
 * @package Moksa\AI
 */

declare( strict_types=1 );

namespace Moksa\AI;

defined( 'ABSPATH' ) || exit;

final class Host {

	const REST_NS = 'moksa-ai/v1';

	private static bool $booted = false;
	private static string $dir  = '';

	/** $dir = 選舉勝出那份副本的目錄（資源都在它底下）。 */
	public static function init( string $dir ): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		self::$dir    = $dir;

		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function enqueue(): void {
		// 判斷延到這裡才做：各外掛註冊 abilities 的時機不一致，在 boot 當下判斷是賭 hook 順序。
		if ( ! Registry::has_agent() || ! current_user_can( Registry::capability() ) ) {
			return;
		}
		// 樣式由 chat.js 自己注入（它是自足元件），所以只需要 dashicons + 這支 JS。
		$js  = plugins_url( 'assets/chat.js', self::$dir . '/moksa-ai.php' );
		$ver = (string) ( file_exists( self::$dir . '/assets/chat.js' ) ? filemtime( self::$dir . '/assets/chat.js' ) : '1' );

		wp_enqueue_style( 'dashicons' );
		wp_enqueue_script( 'moksa-ai-chat', $js, array( 'wp-api-fetch' ), $ver, true );

		$s = Registry::strings();
		wp_localize_script(
			'moksa-ai-chat',
			'moksaAiChat',
			array(
				'restChat'    => esc_url_raw( rest_url( self::REST_NS . '/chat' ) ),
				'restConfirm' => esc_url_raw( rest_url( self::REST_NS . '/confirm' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'userId'      => get_current_user_id(),
				'strings'     => $s,
			)
		);
	}

	public static function routes(): void {
		register_rest_route(
			self::REST_NS,
			'/chat',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn(): bool => current_user_can( Registry::capability() ),
				'args'                => array(
					'message' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'history' => array(
						'type'     => 'array',
						'required' => false,
						'default'  => array(),
					),
				),
				'callback'            => array( self::class, 'chat' ),
			)
		);

		register_rest_route(
			self::REST_NS,
			'/confirm',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn(): bool => current_user_can( Registry::capability() ),
				'args'                => array(
					'token' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'callback'            => array( self::class, 'confirm' ),
			)
		);
	}

	public static function chat( \WP_REST_Request $request ): \WP_REST_Response {
		$message = trim( (string) $request->get_param( 'message' ) );
		if ( '' === $message ) {
			return rest_ensure_response( array( 'reply' => '' ) );
		}

		$result = Agent::run( $message, self::sanitize_history( $request->get_param( 'history' ) ) );
		$type   = $result['type'] ?? 'text';

		if ( 'confirm' === $type ) {
			return rest_ensure_response(
				array(
					'confirm' => array(
						'token'   => (string) ( $result['token'] ?? '' ),
						'summary' => (string) ( $result['summary'] ?? '' ),
					),
				)
			);
		}
		if ( 'error' === $type ) {
			return rest_ensure_response( array( 'error' => (string) ( $result['message'] ?? '' ) ) );
		}
		return rest_ensure_response( array( 'reply' => (string) ( $result['reply'] ?? '' ) ) );
	}

	/**
	 * 消毒前端帶來的對話歷史。只取最近 10 則、每則裁長。
	 *
	 * @param mixed $raw 前端傳的 history。
	 * @return array<int, array{role:string, text:string}>
	 */
	private static function sanitize_history( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $turn ) {
			if ( ! is_array( $turn ) ) {
				continue;
			}
			$role = ( isset( $turn['role'] ) && 'assistant' === $turn['role'] ) ? 'assistant' : 'user';
			$text = isset( $turn['text'] ) ? trim( sanitize_textarea_field( (string) $turn['text'] ) ) : '';
			if ( '' === $text ) {
				continue;
			}
			if ( mb_strlen( $text ) > 2000 ) {
				$text = mb_substr( $text, 0, 2000 );
			}
			$out[] = array(
				'role' => $role,
				'text' => $text,
			);
		}
		return array_slice( $out, -10 );
	}

	/**
	 * 使用者按「確認執行」後，驗證一次性 token 並真正執行破壞性動作。
	 */
	public static function confirm( \WP_REST_Request $request ): \WP_REST_Response {
		$token = (string) $request->get_param( 'token' );
		$key   = Agent::CONFIRM_PREFIX . $token;
		$data  = get_transient( $key );

		if ( ! is_array( $data ) ) {
			return rest_ensure_response( array( 'error' => Registry::string( 'confirmStale' ) ) );
		}
		if ( (int) ( $data['user'] ?? 0 ) !== get_current_user_id() ) {
			return rest_ensure_response( array( 'error' => Registry::string( 'noPermission' ) ) );
		}

		delete_transient( $key );

		$ability  = (string) ( $data['ability'] ?? '' );
		$handlers = Registry::destructive_handlers();
		if ( ! isset( $handlers[ $ability ]['apply'] ) ) {
			return rest_ensure_response( array( 'error' => Registry::string( 'unsupported' ) ) );
		}

		$result = call_user_func( $handlers[ $ability ]['apply'], (array) ( $data['params'] ?? array() ) );
		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( array( 'error' => $result->get_error_message() ) );
		}
		if ( is_array( $result ) && isset( $result['print_url'] ) ) {
			return rest_ensure_response(
				array(
					'reply'     => (string) ( $result['message'] ?? '' ),
					'print_url' => esc_url_raw( (string) $result['print_url'] ),
				)
			);
		}
		return rest_ensure_response( array( 'reply' => (string) $result ) );
	}
}
