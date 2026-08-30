<?php

declare( strict_types=1 );

namespace Moksafopoi\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Compliant stateless MCP (Model Context Protocol) Streamable HTTP server.
 *
 * Wraps this plugin's registered `points/*` WP Abilities as MCP tools 1:1 so any standard
 * MCP client (mcp-remote / Claude's built-in HTTP client) can connect directly with no
 * bridge. Mirrors moforcoupon's self-built Server. Design:
 * - Stateless: initialize sends no Mcp-Session-Id and later requests do not require one.
 * - Compliant tool objects: name / description / inputSchema / outputSchema / annotations.
 * - outputSchema is always an object (non-object schemas are wrapped, and the call result
 *   is wrapped to match).
 * - tools/list is cached (transient keyed by version + destructive-exposure + the exposed
 *   ability names, so it self-invalidates whenever the available tool set changes).
 * - Auth: permission_callback requires manage_woocommerce (Application Password Basic auth
 *   or cookie+nonce); destructive abilities are hidden by default (opt-in option).
 *
 * Runs in parallel with the official WordPress MCP Adapter (detected at runtime via
 * class_exists): abilities also carry meta.mcp.public=true so an installed adapter can
 * discover them too; absent the adapter, this self-built server still serves them over REST.
 */
final class Server {

	const NS       = 'moksafopoi/v1';
	const ROUTE    = '/mcp';
	const PROTOCOL = '2025-06-18';

	/** Ability-name prefix this server exposes (and tool-name prefix after slash→dash). */
	const ABILITY_PREFIX = 'points/';
	const TOOL_PREFIX    = 'points-';

	public static function enabled(): bool {
		return 'yes' === get_option( 'moksafopoi_mcp_server_enabled', 'no' );
	}

	public static function endpoint_url(): string {
		return rest_url( self::NS . self::ROUTE );
	}

	public static function register(): void {
		if ( ! self::enabled() ) {
			return;
		}
		register_rest_route(
			self::NS,
			self::ROUTE,
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'handle' ),
					'permission_callback' => array( self::class, 'authorize' ),
				),
				// This server offers no SSE stream / session termination → spec requires 405 for GET/DELETE.
				array(
					'methods'             => 'GET, DELETE',
					'callback'            => array( self::class, 'method_not_allowed' ),
					'permission_callback' => array( self::class, 'authorize' ),
				),
			)
		);
	}

	public static function authorize(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	public static function method_not_allowed(): \WP_REST_Response {
		$response = new \WP_REST_Response( null, 405 );
		$response->header( 'Allow', 'POST' );
		return $response;
	}

	/**
	 * @param \WP_REST_Request $request JSON-RPC request (single or batch).
	 * @return \WP_REST_Response
	 */
	public static function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || array() === $body ) {
			return new \WP_REST_Response( self::rpc_error( null, -32700, 'Parse error' ), 200 );
		}

		if ( array_is_list( $body ) ) {
			if ( count( $body ) > 50 ) {
				return new \WP_REST_Response( self::rpc_error( null, -32600, 'Batch too large' ), 200 );
			}
			$out = array();
			foreach ( $body as $msg ) {
				$res = self::dispatch( is_array( $msg ) ? $msg : array() );
				if ( null !== $res ) {
					$out[] = $res;
				}
			}
			return new \WP_REST_Response( array() === $out ? null : $out, array() === $out ? 202 : 200 );
		}

		$res = self::dispatch( $body );
		if ( null === $res ) {
			return new \WP_REST_Response( null, 202 ); // notification → no response.
		}
		return new \WP_REST_Response( $res, 200 );
	}

	/**
	 * @param array<string,mixed> $msg Single JSON-RPC message.
	 * @return array<string,mixed>|null null = notification (no response).
	 */
	private static function dispatch( array $msg ): ?array {
		$id      = $msg['id'] ?? null;
		$method  = isset( $msg['method'] ) ? (string) $msg['method'] : '';
		$params  = isset( $msg['params'] ) && is_array( $msg['params'] ) ? $msg['params'] : array();
		$is_note = ! array_key_exists( 'id', $msg );

		switch ( $method ) {
			case 'initialize':
				// Return the version this server supports (spec: do not echo the client version).
				return self::rpc_result(
					$id,
					array(
						'protocolVersion' => self::PROTOCOL,
						'capabilities'    => array(
							'tools' => array( 'listChanged' => false ),
						),
						'serverInfo'      => array(
							'name'    => 'moksa-points-for-woocommerce',
							'title'   => 'Moksa Points',
							'version' => MOKSAFOPOI_VERSION,
						),
					)
				);

			case 'notifications/initialized':
			case 'notifications/cancelled':
				return null;

			case 'ping':
				return self::rpc_result( $id, (object) array() );

			case 'tools/list':
				return self::rpc_result( $id, array( 'tools' => self::tools() ) );

			case 'tools/call':
				return self::call_tool( $id, $params );

			default:
				return $is_note ? null : self::rpc_error( $id, -32601, 'Method not found: ' . $method );
		}
	}

	/**
	 * The abilities this plugin exposes (name => WP_Ability), applying mcp.public + the
	 * destructive gate.
	 *
	 * @return array<string, object>
	 */
	private static function abilities(): array {
		$out = array();
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return $out;
		}
		$expose_destructive = 'yes' === get_option( 'moksafopoi_mcp_expose_destructive', 'no' );
		// WordPress 7.1 can narrow the registry for us (a busy install registers a hundred or more
		// abilities; only a handful are ours). The prefix check below still stays: the argument is 7.1-only and PHP
		// silently ignores extra arguments to a user-defined function, so on 6.9 / 7.0 this call
		// returns EVERY ability and without the check we would hand another plugin's abilities to
		// our own MCP server.
		foreach ( wp_get_abilities( array( 'namespace' => rtrim( self::ABILITY_PREFIX, '/' ) ) ) as $ability ) {
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) ) {
				continue;
			}
			$name = (string) $ability->get_name();
			if ( 0 !== strpos( $name, self::ABILITY_PREFIX ) ) {
				continue;
			}
			$meta = (array) $ability->get_meta();
			$mcp  = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : array();
			if ( array_key_exists( 'public', $mcp ) && ! $mcp['public'] ) {
				continue;
			}
			$ann = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
			if ( ! empty( $ann['destructive'] ) && ! $expose_destructive ) {
				continue;
			}
			$out[ $name ] = $ability;
		}
		return $out;
	}

	/**
	 * @return array<int, array<string,mixed>>
	 */
	private static function tools(): array {
		// Fold the current ability NAMES into the cache key so the cache self-invalidates the
		// moment the exposed tool set changes — a module toggled on/off, the expose-destructive
		// option, a filter, or a plugin update — with no manual hooks.
		$abilities = self::abilities();
		$key       = 'moksafopoi_mcp_tools_' . md5(
			MOKSAFOPOI_VERSION . '|'
			. get_option( 'moksafopoi_mcp_expose_destructive', 'no' ) . '|'
			. implode( ',', array_keys( $abilities ) )
		);
		$cached    = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$tools = array();
		foreach ( $abilities as $ability ) {
			$tools[] = self::tool_schema( $ability );
		}
		set_transient( $key, $tools, DAY_IN_SECONDS );
		return $tools;
	}


	/**
	 * Prepare a schema for an external client. WordPress 7.1 strips server-only keys
	 * (sanitize_callback / validate_callback / arg_options) and normalises property-level
	 * `required` into the JSON Schema array form; the 7.1 dev note asks MCP tools specifically to
	 * run schemas through it before they leave the server. On 6.9 / 7.0 the schema is passed
	 * through untouched.
	 *
	 * @param mixed $schema
	 * @return mixed
	 */
	private static function for_client( $schema ) {
		if ( ! is_array( $schema ) || array() === $schema ) {
			return $schema;
		}
		return function_exists( 'wp_prepare_json_schema_for_client' )
			? (array) wp_prepare_json_schema_for_client( $schema )
			: $schema;
	}

	/**
	 * @param object $ability WP_Ability.
	 * @return array<string,mixed>
	 */
	private static function tool_schema( $ability ): array {
		$name  = (string) $ability->get_name();
		$meta  = (array) $ability->get_meta();
		$ann   = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		$input = self::for_client( $ability->get_input_schema() );
		$input = is_array( $input ) && ! empty( $input )
			? $input
			: array(
				'type'       => 'object',
				'properties' => (object) array(),
			);

		$tool = array(
			'name'        => self::tool_name( $name ),
			'description' => (string) $ability->get_description(),
			'inputSchema' => $input,
			'annotations' => array(
				'title'           => (string) $ability->get_label(),
				'readOnlyHint'    => ! empty( $ann['readonly'] ),
				'destructiveHint' => ! empty( $ann['destructive'] ),
				'idempotentHint'  => ! empty( $ann['idempotent'] ),
				'openWorldHint'   => false,
			),
		);

		$out = self::for_client( $ability->get_output_schema() );
		if ( is_array( $out ) && ! empty( $out ) ) {
			$wrap_key             = self::wrap_key( $out );
			$tool['outputSchema'] = null === $wrap_key
				? $out
				: array(
					'type'       => 'object',
					'properties' => array( $wrap_key => $out ),
					'required'   => array( $wrap_key ),
				);
		}
		return $tool;
	}

	/**
	 * @param mixed               $id     JSON-RPC id.
	 * @param array<string,mixed> $params { name, arguments }.
	 * @return array<string,mixed>
	 */
	private static function call_tool( $id, array $params ): array {
		$tool_name = isset( $params['name'] ) ? (string) $params['name'] : '';
		$args      = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		$abilities    = self::abilities();
		$ability_name = self::ability_name( $tool_name );
		if ( '' === $ability_name || ! isset( $abilities[ $ability_name ] ) ) {
			return self::rpc_error( $id, -32602, 'Unknown tool: ' . $tool_name );
		}
		$ability = $abilities[ $ability_name ];

		// Is this a write? The annotation the tool list already publishes is the same one we gate on,
		// so what a client is told about a tool and how it is policed can never disagree.
		$meta        = (array) $ability->get_meta();
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		$destructive = ! empty( $annotations['destructive'] );

		$perm = $ability->check_permissions( $args );
		if ( is_wp_error( $perm ) || false === $perm ) {
			CallGuard::log( $tool_name, $destructive, 'denied', $args );
			return self::tool_error( $id, __( 'You do not have permission to do this.', 'moksa-points-for-woocommerce' ) );
		}

		// Rate-limit writes. An external MCP call is UNATTENDED: without a cap, one runaway loop (or
		// one stolen application password) can issue thousands of writes before anyone notices.
		if ( $destructive && ! CallGuard::allow_destructive( get_current_user_id() ) ) {
			CallGuard::log( $tool_name, true, 'rate_limited', $args );
			return self::tool_error(
				$id,
				sprintf(
					/* translators: %s: the hourly limit on change-making tool calls. */
					__( 'Rate limit reached: at most %s change-making calls per hour. Try again later.', 'moksa-points-for-woocommerce' ),
					number_format_i18n( CallGuard::limit() )
				)
			);
		}
		if ( $destructive ) {
			CallGuard::record( get_current_user_id() );
		}

		try {
			$result = $ability->execute( $args );
		} catch ( \Throwable $e ) {
			CallGuard::log( $tool_name, $destructive, 'exception', $args );
			$message = ( defined( 'WP_DEBUG' ) && WP_DEBUG )
				? $e->getMessage()
				: __( 'The tool failed to run. Please try again later.', 'moksa-points-for-woocommerce' );
			return self::tool_error( $id, $message );
		}
		if ( is_wp_error( $result ) ) {
			CallGuard::log( $tool_name, $destructive, 'error', $args );
			return self::tool_error( $id, $result->get_error_message() );
		}

		CallGuard::log( $tool_name, $destructive, 'ok', $args );

		$wrap_key   = self::wrap_key( $ability->get_output_schema() );
		$structured = null !== $wrap_key ? array( $wrap_key => $result ) : $result;

		return self::rpc_result(
			$id,
			array(
				'content'           => array(
					array(
						'type' => 'text',
						'text' => (string) wp_json_encode( $structured, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ),
					),
				),
				'structuredContent' => $structured,
				'isError'           => false,
			)
		);
	}

	/**
	 * Non-object output schemas must be wrapped to be compliant; returns the wrap key
	 * (null = no wrap needed).
	 *
	 * @param mixed $out output schema.
	 */
	private static function wrap_key( $out ): ?string {
		if ( ! is_array( $out ) || empty( $out ) ) {
			return null;
		}
		return ( isset( $out['type'] ) && 'object' === $out['type'] ) ? null : 'results';
	}

	private static function tool_name( string $ability ): string {
		return str_replace( '/', '-', $ability );
	}

	private static function ability_name( string $tool ): string {
		if ( 0 !== strpos( $tool, self::TOOL_PREFIX ) ) {
			return '';
		}
		return self::ABILITY_PREFIX . substr( $tool, strlen( self::TOOL_PREFIX ) );
	}

	/**
	 * @param mixed                      $id     JSON-RPC id.
	 * @param array<string,mixed>|object $result Result.
	 * @return array<string,mixed>
	 */
	private static function rpc_result( $id, $result ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	/**
	 * @param mixed $id JSON-RPC id.
	 * @return array<string,mixed>
	 */
	private static function rpc_error( $id, int $code, string $message ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}

	/**
	 * Tool execution error → spec requires a normal result with isError=true (not a
	 * JSON-RPC error).
	 *
	 * @param mixed $id JSON-RPC id.
	 * @return array<string,mixed>
	 */
	private static function tool_error( $id, string $message ): array {
		return self::rpc_result(
			$id,
			array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => $message,
					),
				),
				'isError' => true,
			)
		);
	}
}
