<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\TierLadder;

use Moksafopoi\Modules\AbstractModule;
use Moksafopoi\Support\Label;
use Moksafopoi\Support\Tiers;

defined( 'ABSPATH' ) || exit;

/**
 * 可見的排行階梯 — the card that tells a member what they are and what the next rung costs
 * (「距金卡還差 2,400 點」), the table-stakes loyalty surface moforpoints could compute but never
 * showed.
 *
 * The ladder itself (and where it comes from — moformember when installed, native cumulative-earned
 * points when standalone) lives in {@see Tiers}; this module is only the storefront rendering:
 * the「我的點數」card and the `[moksafopoi_tier]` shortcode.
 *
 * Read-only. No tier is ever written here, and nothing touches the ledger.
 */
final class Module extends AbstractModule {

	/** Handle for the module's own (dependency-free) inline style. */
	private const HANDLE = 'moksafopoi-tierladder';

	public function slug(): string {
		return 'tierladder';
	}

	public function label(): string {
		return __( 'Member tier ladder', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'frontend';
	}

	public function tagline(): string {
		return __( 'Show the member\'s current tier and how far the next one is ("2,400 points to Gold"); reads the membership plugin\'s tiers when it is installed, otherwise a points ladder of its own', 'moksa-points-for-woocommerce' );
	}

	/** @return array<int,string> */
	public function requires(): array {
		return array( 'ledger' );
	}

	public function boot(): void {
		add_shortcode( 'moksafopoi_tier', array( self::class, 'shortcode' ) );

		if ( is_admin() ) {
			return;
		}

		// Priority 20: directly under the balance hero, above the「如何賺點」guide (30).
		add_action( 'moksafopoi_after_account_hero', array( self::class, 'render_account' ), 20 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * `[moksafopoi_tier]` — the same card anywhere. Renders nothing for guests.
	 *
	 * @param array<string,string>|string $atts
	 */
	public static function shortcode( $atts = array() ): string {
		unset( $atts );
		return self::html( get_current_user_id() );
	}

	/** Echo the card on the「我的點數」page. */
	public static function render_account(): void {
		echo wp_kses_post( self::html( get_current_user_id() ) );
	}

	/**
	 * The card markup, or '' when there is nothing meaningful to show (a guest, or no ladder at all).
	 */
	public static function html( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}

		$status = Tiers::status( $user_id );
		if ( null === $status || null === $status['tier'] && null === $status['next'] ) {
			return '';
		}

		$current_label = ( null !== $status['tier'] ) ? (string) $status['tier']['label'] : '';
		$percent       = (int) round( ( (float) ( $status['progress'] ?? 0.0 ) ) * 100 );

		$out = '<div class="moksafopoi-tier">';
		$out .= '<h3 class="moksafopoi-tier__title">' . esc_html__( 'My tier', 'moksa-points-for-woocommerce' ) . '</h3>';

		if ( '' !== $current_label ) {
			$out .= '<p class="moksafopoi-tier__current">'
				. esc_html(
					sprintf(
						/* translators: %s: the member's current tier name, e.g.「金卡」. */
						__( 'Current tier: %s', 'moksa-points-for-woocommerce' ),
						$current_label
					)
				) . '</p>';
		}

		if ( $status['top'] ) {
			$out .= '<p class="moksafopoi-tier__next">' . esc_html__( 'You have reached the highest tier — thank you!', 'moksa-points-for-woocommerce' ) . '</p>';
		} else {
			$next = (array) $status['next'];

			if ( null === $status['remaining'] ) {
				// The membership plugin decides this member's tier, so we name the next rung but do
				// not invent a points distance to it.
				$out .= '<p class="moksafopoi-tier__next">'
					. esc_html(
						sprintf(
							/* translators: %s: the next tier name. */
							__( 'Next tier: %s', 'moksa-points-for-woocommerce' ),
							(string) $next['label']
						)
					) . '</p>';
			} else {
				$out .= '<p class="moksafopoi-tier__next">'
					. esc_html(
						sprintf(
							/* translators: 1: points still needed, with unit, e.g.「2,400 點」; 2: the next tier name. */
							__( '%1$s to go until %2$s', 'moksa-points-for-woocommerce' ),
							Label::format( (int) $status['remaining'] ),
							(string) $next['label']
						)
					) . '</p>';
				$out .= '<div class="moksafopoi-tier__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" '
					. 'aria-valuenow="' . esc_attr( (string) $percent ) . '">'
					. '<span class="moksafopoi-tier__fill" style="width:' . esc_attr( (string) $percent ) . '%"></span></div>';
			}
		}

		$out .= '<p class="moksafopoi-tier__basis">'
			. esc_html(
				sprintf(
					/* translators: %s: the member's lifetime earned points, with unit. */
					__( 'Lifetime points earned: %s', 'moksa-points-for-woocommerce' ),
					Label::format( (int) $status['basis'] )
				)
			) . '</p>';

		$out .= self::ladder_list( $status );
		$out .= '</div>';

		return $out;
	}

	/**
	 * The whole ladder, so a member can see what is above them — the part that actually motivates.
	 *
	 * @param array<string,mixed> $status
	 */
	private static function ladder_list( array $status ): string {
		$ladder = Tiers::ladder();
		if ( count( $ladder ) < 2 ) {
			return '';
		}

		$current_key = ( is_array( $status['tier'] ) ) ? (string) $status['tier']['key'] : '';
		// When the membership plugin names the tier, our thresholds are not the criterion: no rung is
		// marked "already passed" and no points requirement is quoted — only the current rung is
		// highlighted, and the ladder reads as a plain list of tiers.
		$named_by_sibling = ! empty( $status['named'] );
		$basis            = $named_by_sibling ? PHP_INT_MIN : (int) $status['basis'];

		$out = '<ul class="moksafopoi-tier__list">';
		foreach ( $ladder as $tier ) {
			$classes = 'moksafopoi-tier__step';
			if ( $tier['key'] === $current_key ) {
				$classes .= ' is-current';
			} elseif ( $basis >= (int) $tier['threshold'] ) {
				$classes .= ' is-passed';
			}
			$out .= '<li class="' . esc_attr( $classes ) . '">'
				. '<span class="moksafopoi-tier__step-name">' . esc_html( (string) $tier['label'] ) . '</span> ';
			// Only quote a points requirement when points ARE the requirement.
			if ( ! $named_by_sibling ) {
				$out .= '<span class="moksafopoi-tier__step-need">' . esc_html( Label::format( (int) $tier['threshold'] ) ) . '</span>';
			}
			$out .= '</li>';
		}
		$out .= '</ul>';

		return $out;
	}

	/** Inline styles on the module's own handle (registered at enqueue time, never echoed). */
	public static function enqueue(): void {
		wp_register_style( self::HANDLE, false, array(), MOKSAFOPOI_VERSION );
		wp_enqueue_style( self::HANDLE );
		wp_add_inline_style(
			self::HANDLE,
			'.moksafopoi-tier{margin:20px 0}'
			. '.moksafopoi-tier__current{margin:0 0 4px;font-weight:600}'
			. '.moksafopoi-tier__next{margin:0 0 8px;color:#b45309}'
			. '.moksafopoi-tier__bar{height:10px;border-radius:999px;background:#eceff3;overflow:hidden}'
			. '.moksafopoi-tier__fill{display:block;height:100%;background:linear-gradient(90deg,#f59e0b,#f97316)}'
			. '.moksafopoi-tier__basis{margin:8px 0 0;color:#555;font-size:14px}'
			. '.moksafopoi-tier__list{list-style:none;margin:10px 0 0;padding:0;display:grid;gap:4px}'
			. '.moksafopoi-tier__step{display:flex;justify-content:space-between;gap:12px;padding:6px 10px;'
			. 'border:1px solid #e6e8eb;border-radius:6px;font-size:14px;color:#666}'
			. '.moksafopoi-tier__step.is-passed{color:#137333;border-color:#cfe8d6}'
			. '.moksafopoi-tier__step.is-current{color:#b45309;border-color:#f5c78a;background:#fff8ec;font-weight:600}'
		);
	}
}
