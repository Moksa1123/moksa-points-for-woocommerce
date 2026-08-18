/* global jQuery, moksafopoiWallet, wc, wp */
( function ( $ ) {
	'use strict';

	/** Native block Cart/Checkout API present? (lets us update totals without a full reload.) */
	function blockApi() {
		return ( window.wc && window.wc.blocksCheckout && typeof window.wc.blocksCheckout.extensionCartUpdate === 'function' )
			? window.wc.blocksCheckout.extensionCartUpdate
			: null;
	}

	function post( action, data ) {
		return $.post( moksafopoiWallet.ajaxUrl, $.extend( { action: action }, data ) );
	}

	/** Read the live wallet extension data the Store API put on the cart. */
	function cartExt() {
		try {
			var data = wp.data.select( 'wc/store/cart' ).getCartData();
			return ( data && data.extensions && data.extensions[ 'moksafopoi-wallet' ] ) || null;
		} catch ( e ) {
			return null;
		}
	}

	/** Re-render the panel's applied/form state from the cart extension data (no reload). */
	function refreshPanel() {
		var $panel = $( '#moksafopoi-wallet-panel' );
		if ( ! $panel.length ) {
			return;
		}
		var ext = cartExt();
		var applied = ext ? parseFloat( ext.applied_amount ) || 0 : 0;
		var $applied = $panel.find( '.moksafopoi-wallet-applied' );
		var $form = $panel.find( '.moksafopoi-wallet-form' );
		var $remove = $panel.find( '.moksafopoi-wallet-remove' );
		if ( applied > 0 ) {
			$applied.text( moksafopoiWallet.appliedText.replace( '%s', applied ) ).show();
			$form.hide();
			$remove.show();
		} else {
			$applied.hide();
			$form.show();
			$remove.hide();
		}
	}

	function setViaBlock( amount, $btn ) {
		var fn = blockApi();
		$btn.prop( 'disabled', true );
		fn( { namespace: 'moksafopoi-wallet', data: { amount: amount } } )
			.then( function () {
				refreshPanel();
				$btn.prop( 'disabled', false );
			} )
			.catch( function () {
				$btn.prop( 'disabled', false );
			} );
	}

	$( document.body ).on( 'click', '.moksafopoi-wallet-apply', function () {
		var $btn = $( this );
		var amount = parseInt( $( '#moksafopoi-wallet-input' ).val(), 10 ) || 0;
		if ( blockApi() ) {
			setViaBlock( amount, $btn );
			return;
		}
		$btn.prop( 'disabled', true );
		post( 'moksafopoi_apply_wallet', { nonce: $btn.data( 'nonce' ), amount: amount } )
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

	$( document.body ).on( 'click', '.moksafopoi-wallet-remove', function () {
		var $btn = $( this );
		if ( blockApi() ) {
			setViaBlock( 0, $btn );
			return;
		}
		$btn.prop( 'disabled', true );
		post( 'moksafopoi_remove_wallet', { nonce: $btn.data( 'nonce' ) } )
			.done( function () {
				window.location.reload();
			} )
			.fail( function () {
				$btn.prop( 'disabled', false );
			} );
	} );
}( jQuery ) );
