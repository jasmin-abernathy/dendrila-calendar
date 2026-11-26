<?php
/** Public, privacy-safe booking form. All availability and writes remain server-owned. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Dendrila_Calendar_Booking_Form {
    public static function boot() {
        add_shortcode( 'dendrila_booking', array( __CLASS__, 'render' ) );
    }

    /** Accept either a service object or the ID returned by the public service list. */
    private static function service_id( $item ) {
        if ( is_array( $item ) ) {
            return absint( $item['service_id'] ?? $item['ID'] ?? $item['id'] ?? 0 );
        }
        if ( is_object( $item ) ) {
            return absint( $item->service_id ?? $item->ID ?? $item->id ?? 0 );
        }
        return absint( $item );
    }

    /** Explicit public allowlist: never serialize a post, booking or private configuration. */
    private static function public_fields( $service ) {
        if ( ! is_array( $service ) || empty( $service['service_id'] ) ) {
            return null;
        }
        $public = array(
            'service_id' => absint( $service['service_id'] ),
            'profile_id' => absint( $service['profile_id'] ?? 0 ),
            'duration_minutes' => absint( $service['duration_minutes'] ?? 0 ),
            'requires_payment' => ! empty( $service['requires_payment'] ),
        );
        foreach ( array( 'title', 'profile_title', 'timezone', 'min_date', 'max_date', 'price_text' ) as $key ) {
            $public[ $key ] = isset( $service[ $key ] ) && is_scalar( $service[ $key ] ) ? (string) $service[ $key ] : '';
        }
        return $public;
    }

    public static function render( $attributes = array() ) {
        if ( ! class_exists( 'Dendrila_Calendar_Booking' ) ) {
            return '<p class="dendrila-booking-unavailable">' . esc_html__( 'Online booking is not available at the moment.', 'dendrila-calendar' ) . '</p>';
        }
        $attributes = shortcode_atts( array( 'service' => '', 'service_id' => '' ), $attributes, 'dendrila_booking' );
        $fixed = '' !== (string) $attributes['service'] || '' !== (string) $attributes['service_id'];
        $requested = absint( '' !== (string) $attributes['service'] ? $attributes['service'] : $attributes['service_id'] );
        $candidates = $fixed ? array( $requested ) : Dendrila_Calendar_Booking::public_services();
        $services = array();
        foreach ( is_array( $candidates ) ? $candidates : array() as $candidate ) {
            $id = self::service_id( $candidate );
            $data = $id ? Dendrila_Calendar_Booking::public_service( $id ) : null;
            $public = self::public_fields( $data );
            if ( $public ) {
                $services[ $public['service_id'] ] = $public;
            }
        }
        if ( ! $services ) {
            return '<p class="dendrila-booking-unavailable">' . esc_html__( 'No service is currently available for online booking.', 'dendrila-calendar' ) . '</p>';
        }

        $base = dirname( __DIR__ );
        $url = plugin_dir_url( $base . '/dendrila-calendar.php' );
        wp_enqueue_style( 'dendrila-calendar-booking', $url . 'assets/calendar-booking.css', array(), (string) filemtime( $base . '/assets/calendar-booking.css' ) );
        wp_enqueue_script( 'dendrila-calendar-booking', $url . 'assets/calendar-booking.js', array(), (string) filemtime( $base . '/assets/calendar-booking.js' ), true );
        $id = wp_unique_id( 'dendrila-booking-' );
        $privacy_url = get_privacy_policy_url();
        $config = array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'dendrila_booking_public' ),
            'locale' => str_replace( '_', '-', get_locale() ),
            'services' => array_values( $services ),
            'i18n' => self::strings(),
        );

        ob_start();
        ?>
        <section class="dendrila-booking" data-dendrila-booking aria-labelledby="<?php echo esc_attr( $id ); ?>-title">
            <div class="dc-booking-heading">
                <p class="dc-booking-eyebrow"><?php esc_html_e( 'Online booking', 'dendrila-calendar' ); ?></p>
                <h2 id="<?php echo esc_attr( $id ); ?>-title"><?php esc_html_e( 'Choose a time that works for you', 'dendrila-calendar' ); ?></h2>
                <p><?php esc_html_e( 'Choose a service and an available time, then enter your details.', 'dendrila-calendar' ); ?></p>
            </div>
            <div class="dc-booking-status" data-dc-status role="status" aria-live="polite" aria-atomic="true" tabindex="-1"></div>
            <p data-dc-loading><?php esc_html_e( 'Loading the booking form…', 'dendrila-calendar' ); ?></p>
            <noscript><p class="dc-booking-note"><?php esc_html_e( 'JavaScript is required to check available times and make a booking. Enable it in your browser and reload this page.', 'dendrila-calendar' ); ?></p></noscript>
            <form data-dc-form method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" novalidate hidden>
                <fieldset class="dc-booking-fields" data-dc-fields>
                    <legend class="dc-sr-only"><?php esc_html_e( 'Booking details', 'dendrila-calendar' ); ?></legend>
                    <div class="dc-booking-choices">
                        <div class="dc-booking-field">
                            <label for="<?php echo esc_attr( $id ); ?>-service"><?php esc_html_e( 'Service', 'dendrila-calendar' ); ?></label>
                            <select id="<?php echo esc_attr( $id ); ?>-service" name="service_id" data-dc-service required>
                                <?php if ( count( $services ) > 1 ) : ?>
                                    <option value=""><?php esc_html_e( 'Choose a service', 'dendrila-calendar' ); ?></option>
                                <?php endif; ?>
                                <?php foreach ( $services as $service ) : ?>
                                    <option value="<?php echo esc_attr( $service['service_id'] ); ?>"><?php echo esc_html( $service['title'] ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="dc-booking-field">
                            <label for="<?php echo esc_attr( $id ); ?>-date"><?php esc_html_e( 'Date', 'dendrila-calendar' ); ?></label>
                            <input id="<?php echo esc_attr( $id ); ?>-date" name="date" data-dc-date type="date" required disabled aria-describedby="<?php echo esc_attr( $id ); ?>-timezone">
                        </div>
                    </div>
                    <div class="dc-booking-service-info" data-dc-service-info hidden>
                        <p data-dc-details></p>
                        <p id="<?php echo esc_attr( $id ); ?>-timezone" data-dc-timezone></p>
                    </div>
                    <fieldset class="dc-booking-slots" data-dc-slots-fieldset hidden>
                        <legend><?php esc_html_e( 'Available times', 'dendrila-calendar' ); ?></legend>
                        <p class="dc-booking-slot-note" data-dc-slot-note></p>
                        <div class="dc-booking-slot-grid" data-dc-slots></div>
                    </fieldset>
                    <div class="dc-booking-contact" data-dc-contact hidden>
                        <p class="dc-booking-selection" data-dc-selection></p>
                        <div class="dc-booking-choices">
                            <div class="dc-booking-field">
                                <label for="<?php echo esc_attr( $id ); ?>-name"><?php esc_html_e( 'Your name', 'dendrila-calendar' ); ?></label>
                                <input id="<?php echo esc_attr( $id ); ?>-name" name="name" data-dc-name type="text" autocomplete="name" maxlength="200" required>
                            </div>
                            <div class="dc-booking-field">
                                <label for="<?php echo esc_attr( $id ); ?>-email"><?php esc_html_e( 'Email address', 'dendrila-calendar' ); ?></label>
                                <input id="<?php echo esc_attr( $id ); ?>-email" name="email" data-dc-email type="email" autocomplete="email" inputmode="email" maxlength="254" required>
                            </div>
                        </div>
                        <div class="dc-booking-honey" aria-hidden="true">
                            <label for="<?php echo esc_attr( $id ); ?>-website"><?php esc_html_e( 'Leave this field empty', 'dendrila-calendar' ); ?></label>
                            <input id="<?php echo esc_attr( $id ); ?>-website" name="website" data-dc-website type="text" tabindex="-1" autocomplete="off">
                        </div>
                        <label class="dc-booking-consent">
                            <input type="checkbox" name="consent" value="1" data-dc-consent required>
                            <span><?php esc_html_e( 'I agree that my name and email address may be used to manage this booking.', 'dendrila-calendar' ); ?></span>
                        </label>
                        <?php if ( $privacy_url ) : ?>
                            <p class="dc-booking-privacy"><a href="<?php echo esc_url( $privacy_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Read the privacy policy (opens in a new tab)', 'dendrila-calendar' ); ?></a></p>
                        <?php endif; ?>
                        <p class="dc-booking-note" data-dc-next-step></p>
                        <button class="dc-booking-primary" data-dc-submit type="submit" disabled><?php esc_html_e( 'Confirm booking', 'dendrila-calendar' ); ?></button>
                    </div>
                </fieldset>
            </form>
            <div class="dc-booking-result" data-dc-result hidden>
                <h3 data-dc-result-title tabindex="-1"></h3>
                <p data-dc-result-description></p>
                <dl data-dc-result-details></dl>
                <p class="dc-booking-note" data-dc-result-note></p>
                <div class="dc-booking-result-actions">
                    <a class="dc-booking-primary" data-dc-checkout hidden></a>
                    <button class="dc-booking-secondary" type="button" data-dc-ics hidden><?php esc_html_e( 'Add to my calendar (.ics)', 'dendrila-calendar' ); ?></button>
                    <button class="dc-booking-secondary" type="button" data-dc-cancel hidden><?php esc_html_e( 'Cancel this booking', 'dendrila-calendar' ); ?></button>
                </div>
                <div class="dc-booking-cancel-confirm" data-dc-cancel-confirm hidden>
                    <p><?php esc_html_e( 'Cancel this booking and release the time?', 'dendrila-calendar' ); ?></p>
                    <div class="dc-booking-result-actions">
                        <button class="dc-booking-danger" type="button" data-dc-cancel-yes><?php esc_html_e( 'Yes, cancel this booking', 'dendrila-calendar' ); ?></button>
                        <button class="dc-booking-secondary" type="button" data-dc-cancel-no><?php esc_html_e( 'Keep my booking', 'dendrila-calendar' ); ?></button>
                    </div>
                </div>
            </div>
            <script type="application/json" data-dendrila-booking-config><?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON is inert and HTML-escaped. ?></script>
        </section>
        <?php
        return ob_get_clean();
    }

    private static function strings() {
        return array(
            'unavailable' => __( 'Online booking is not available at the moment. Reload this page to try again.', 'dendrila-calendar' ),
            'unsupported' => __( 'Your browser cannot securely submit a booking. Please use an up-to-date browser.', 'dendrila-calendar' ),
            'chooseService' => __( 'Choose a service to see available times.', 'dendrila-calendar' ),
            'chooseDate' => __( 'Choose a date within the available booking period.', 'dendrila-calendar' ),
            'loading' => __( 'Checking available times…', 'dendrila-calendar' ),
            'empty' => __( 'No times are available on this date. Please choose another date.', 'dendrila-calendar' ),
            'chooseSlot' => __( 'Choose an available time below.', 'dendrila-calendar' ),
            'minutes' => __( '%s minutes', 'dendrila-calendar' ),
            'timezone' => __( 'Times shown in %s.', 'dendrila-calendar' ),
            'localTimezone' => __( 'Your local times are also shown in %s.', 'dendrila-calendar' ),
            'yourTime' => __( 'Your time: %s', 'dendrila-calendar' ),
            'selected' => __( 'Selected time: %s', 'dendrila-calendar' ),
            'confirm' => __( 'Confirm booking', 'dendrila-calendar' ),
            'reserve' => __( 'Reserve and continue to payment', 'dendrila-calendar' ),
            'confirmNote' => __( 'Your confirmation and booking reference will appear here.', 'dendrila-calendar' ),
            'paymentNote' => __( 'Your time will be held while you complete payment. The booking is confirmed after payment.', 'dendrila-calendar' ),
            'sending' => __( 'Saving your booking…', 'dendrila-calendar' ),
            'failed' => __( 'This request could not be completed. Please try again.', 'dendrila-calendar' ),
            'network' => __( 'No confirmation was received. Check your connection, then retry without changing your details.', 'dendrila-calendar' ),
            'slotsFailed' => __( 'Available times could not be checked. Please try another date or reload this page.', 'dendrila-calendar' ),
            'confirmed' => __( 'Your booking is confirmed', 'dendrila-calendar' ),
            'confirmedDescription' => __( 'Keep your reference and add the appointment to your calendar if you wish.', 'dendrila-calendar' ),
            'held' => __( 'Your time is reserved during payment', 'dendrila-calendar' ),
            'heldDescription' => __( 'Complete the payment to confirm your appointment.', 'dendrila-calendar' ),
            'paymentWaiting' => __( 'Your payment is awaiting confirmation', 'dendrila-calendar' ),
            'paymentWaitingDescription' => __( 'Your appointment time remains reserved while the organizer checks the payment.', 'dendrila-calendar' ),
            'viewOrder' => __( 'View my order', 'dendrila-calendar' ),
            'heldUntil' => __( 'Complete payment before %s. After that, the time can become available again.', 'dendrila-calendar' ),
            'checkout' => __( 'Continue to payment', 'dendrila-calendar' ),
            'checkoutFailed' => __( 'The payment link is unavailable. Keep your booking reference and contact the site to complete payment.', 'dendrila-calendar' ),
            'reference' => __( 'Booking reference', 'dendrila-calendar' ),
            'service' => __( 'Service', 'dendrila-calendar' ),
            'profile' => __( 'With', 'dendrila-calendar' ),
            'when' => __( 'Date and time', 'dendrila-calendar' ),
            'timezoneLabel' => __( 'Time zone', 'dendrila-calendar' ),
            'price' => __( 'Price', 'dendrila-calendar' ),
            'cancelNote' => __( 'You can cancel this booking below while this confirmation remains open.', 'dendrila-calendar' ),
            'cancelling' => __( 'Cancelling your booking…', 'dendrila-calendar' ),
            'cancelled' => __( 'Your booking has been cancelled', 'dendrila-calendar' ),
            'cancelledDescription' => __( 'The time has been released. You can return to this page to make another booking.', 'dendrila-calendar' ),
            'cancelledNote' => __( 'If you already paid, contact the site about its refund policy.', 'dendrila-calendar' ),
            'expired' => __( 'This reservation has expired', 'dendrila-calendar' ),
            'expiredDescription' => __( 'The time is no longer reserved. Reload this page to choose another available time.', 'dendrila-calendar' ),
        );
    }
}
