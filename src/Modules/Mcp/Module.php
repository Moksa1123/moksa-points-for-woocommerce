<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\Mcp;

use Moksafopoi\Mcp\Server;
use Moksafopoi\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * MCP module — boots the self-built 1:1 MCP server (NS `moksafopoi/v1`, route `/mcp`) on
 * `rest_api_init`. The server itself self-gates on the `moksafopoi_mcp_server_enabled`
 * option (default off / opt-in), so even with this module enabled the REST route only
 * registers when the operator turns the server on. Destructive tools stay hidden from
 * tools/list unless `moksafopoi_mcp_expose_destructive='yes'`.
 *
 * Detects the official WordPress MCP Adapter at runtime via class_exists only to note it
 * can co-discover the same abilities (they carry meta.mcp.public=true); the self-built
 * server works with or without the adapter present.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'mcp';
	}

	public function label(): string {
		return __( 'Points MCP server', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'integration';
	}

	public function tagline(): string {
		return __( 'A self-hosted 1:1 MCP server that exposes the points Abilities as tools to AI clients (requires the separate server toggle)', 'moksa-points-for-woocommerce' );
	}

	/** @return array<int,string> */
	public function requires(): array {
		return array( 'ledger', 'abilities' );
	}

	public function boot(): void {
		// The server self-gates on moksafopoi_mcp_server_enabled; registering the hook is cheap.
		add_action( 'rest_api_init', array( Server::class, 'register' ) );
	}
}
