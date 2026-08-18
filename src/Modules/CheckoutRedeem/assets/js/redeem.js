/* global jQuery, moksafopoiRedeem, wc, wp */
( function ( $ ) {
	'use strict';

	/** Native block Cart/Checkout API present? (lets us update totals without a full reload.) */
	function blockApi() {
		return ( window.wc && window.wc.blocksCheckout && typeof window.wc.blocksCheckout.extensionCartUpdate === 'function' )
			? window.wc.blocksCheckout.extensionCartUpdate
			: null;
	}

	function post( action, data ) {
		return $.post( moksafopoiRedeem.ajaxUrl, $.extend( { action: action }, data ) );
	}

	/** Read the live redeem extension data the Store API put on the cart. */
	function cartExt() {
		try {
			var data = wp.data.select( 'wc/store/cart' ).getCartData();
			return ( data && data.extensions && data.extensions[ 'moksafopoi-redeem' ] ) || null;
		} catch ( e ) {
			return null;
		}
	}

	/** Re-render the small panel's applied/form state from the cart extension data (no reload). */
	function refreshPanel() {
		var $panel = $( '#moksafopoi-redeem-panel' );
		if ( ! $panel.length ) {
			return;
		}
		var ext = cartExt();
		var applied = ext ? parseInt( ext.applied_points, 10 ) || 0 : 0;
		var rate = ( ext && ext.redeem_rate ) || moksafopoiRedeem.rate || 100;
		var $applied = $panel.find( '.moksafopoi-redeem-applied' );
		var $form = $panel.find( '.moksafopoi-redeem-form' );
		var $remove = $panel.find( '.moksafopoi-redeem-remove' );
		if ( applied > 0 ) {
			var amount = Math.floor( applied / rate );
			$applied.text( moksafopoiRedeem.appliedText.replace( '%1$s', applied ).replace( '%2$s', amount ) ).show();
			$form.hide();
			$remove.show();
		} else {
			$applied.hide();
			$form.show();
			$remove.hide();
		}
	}

	function setViaBlock( points, $btn ) {
		var fn = blockApi();
		$btn.prop( 'disabled', true );
		fn( { namespace: 'moksafopoi-redeem', data: { points: points } } )
			.then( function () {
				refreshPanel();
				$btn.prop( 'disabled', false );
			} )
			.catch( function () {
				$btn.prop( 'disabled', false );
			} );
	}

	$( document.body ).on( 'click', '.moksafopoi-redeem-apply', function () {
		var $btn = $( this );
		var points = parseInt( $( '#moksafopoi-redeem-input' ).val(), 10 ) || 0;
		if ( blockApi() ) {
			setViaBlock( points, $btn );
			return;
		}
		// Classic [woocommerce_cart] fallback: AJAX + reload.
		$btn.prop( 'disabled', true );
		post( 'moksafopoi_apply_redeem', { nonce: $btn.data( 'nonce' ), points: points } )
			.done( function ( res ) {
				if ( res && res.success ) {
					window.location.reload();
				} else {
					window.alert( ( res && res.data && res.data.message ) || 'Error' );
					$btn.prop( 'disabled', false );
				}
			} )
			.fail( function () {
				$btn.prop( 'disabled', false );
			} );
	} );

	$( document.body ).on( 'click', '.moksafopoi-redeem-remove', function () {
		var $btn = $( this );
		if ( blockApi() ) {
			setViaBlock( 0, $btn );
			return;
		}
		$btn.prop( 'disabled', true );
		post( 'moksafopoi_remove_redeem', { nonce: $btn.data( 'nonce' ) } )
			.done( function () {
				window.location.reload();
			} )
			.fail( function () {
				$btn.prop( 'disabled', false );
			} );
	} );
}( jQuery ) );
