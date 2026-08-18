<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Sibling store-credit intake — the「錢的價值歸 moksafopoi」hand-off lane. Sibling plugins
 * that owe a member money (今天:moforaffiliate 的佣金以儲值金撥付)fire a hook carrying the
 * amount and THEIR deterministic idempotency ref; this always-on listener books it into the
 * store-credit lane through the same idempotent {@see \Moksafopoi\Modules\Ledger\Ledger::record_once()}
 * every other credit uses. A replayed event is a UNIQUE-key no-op, so the sibling can fire
 * at-least-once without ever double-crediting. This plugin never initiates the credit — the
 * money decision stays with the sibling; we only record value.
 */
final class SiblingCredits {

	public static function register(): void {
		add_action( 'moforaffiliate_payout_store_credit', array( self::class, 'on_affiliate_payout' ), 10, 3 );
	}

	/**
	 * moforaffiliate settled a payout batch as store credit.
	 *
	 * @param int    $user_id KOL user id.
	 * @param float  $amount  Batch total (must be positive).
	 * @param string $ref     Sibling's deterministic idempotency ref (`affpayout_<sha1>`).
	 */
	public static function on_affiliate_payout( $user_id, $amount = 0.0, $ref = '' ): void {
		$user_id = (int) $user_id;
		$amount  = (float) $amount;
		$ref     = (string) $ref;
		if ( $user_id <= 0 || $amount <= 0 || '' === $ref ) {
			return;
		}
		$ledger = '\\Moksafopoi\\Modules\\Ledger\\Ledger';
		if ( ! class_exists( $ledger ) ) {
			return;
		}
		$ledger::record_once(
			$user_id,
			0,
			'affiliate_payout',
			'affiliate_payout',
			$ref,
			array(
				'amount_delta' => $amount,
				'note'         => __( 'Affiliate commission credited to store credit', 'moksa-points-for-woocommerce' ),
			)
		);
	}
}
