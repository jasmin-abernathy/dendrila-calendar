<?php
/**
 * Optional WooCommerce bridge contract.
 * No checkout hooks or payment collection until the booking hold lifecycle
 * can be verified against classic and block checkout and HPOS.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Dendrila_Calendar_Woo {
    public static function available() {
        return function_exists( 'wc_get_product' ) && function_exists( 'wc_get_order' );
    }

    /** A reference only: product assignment does not create an order or charge. */
    public static function product_for_service( $service_id ) {
        $service = Dendrila_Calendar_Native::service_data( $service_id );
        if ( ! $service || ! self::available() || ! $service['woo_product_id'] ) {
            return false;
        }
        $product = wc_get_product( $service['woo_product_id'] );
        return $product && $product->exists() && $product->is_purchasable() ? $product : false;
    }

    /** Lookup via Woo CRUD (works with High-Performance Order Storage). */
    public static function order( $order_id ) {
        if ( ! self::available() || absint( $order_id ) < 1 ) {
            return false;
        }
        return wc_get_order( absint( $order_id ) );
    }
}
