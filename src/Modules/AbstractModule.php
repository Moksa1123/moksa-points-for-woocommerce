<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Single entry point for every feature module. A module declares its slug, label and
 * category, and wires its hooks in boot(). Identical contract to moforcoupon's AbstractModule.
 */
abstract class AbstractModule {

	abstract public function slug(): string;

	abstract public function label(): string;

	abstract public function category(): string;

	abstract public function boot(): void;

	public function tagline(): string {
		return '';
	}

	/**
	 * Module keys this one needs to be fully useful (soft advisory, not a hard block).
	 *
	 * @return array<int,string>
	 */
	public function requires(): array {
		return array();
	}
}
