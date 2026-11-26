(function () {
    'use strict';

    function openBookingTab() {
        var box = document.getElementById('woocommerce-product-data');
        if (box && box.classList.contains('closed')) {
            var toggle = box.querySelector('button.handlediv, .hndle');
            if (toggle) toggle.click();
        }
        var tab = document.querySelector('.product_data_tabs li.dendrila_booking_options a, .product_data_tabs a[href="#dendrila-product-booking"]');
        if (!tab) { return false; }
        // WooCommerce owns the selection/display of product-data tabs.
        if (typeof window.jQuery === 'function') {
            window.jQuery(tab).trigger('click');
        } else { tab.click(); }
        var panel = document.getElementById('dendrila-product-booking');
        if (box) box.scrollIntoView({ behavior: 'auto', block: 'start' });
        if (panel) {
            var first = panel.querySelector('input[type="checkbox"][name="dendrila_product_booking"]');
            if (first) first.focus({ preventScroll: true });
        }
        return true;
    }

    function start() {
        var notice = document.querySelector('[data-dendrila-product-help]');
        if (!notice) return;
        var button = notice.querySelector('[data-dendrila-open-booking-tab]');
        var error = notice.querySelector('[data-dendrila-missing-tab]');
        function open() {
            if (!openBookingTab() && error) error.hidden = false;
        }
        if (button) button.addEventListener('click', function (event) {
            if (openBookingTab()) event.preventDefault();
            else if (error) error.hidden = false;
        });
        if (notice.dataset.dendrilaNewService === '1' || window.location.hash === '#dendrila-product-booking') {
            // Let the WooCommerce product-data tabs finish initializing first.
            window.setTimeout(open, 250);
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
}());
