<?php
/** Minimal adapter smoke test without a WordPress installation. */
namespace OryxBookings\Repositories {
    class BookingRepository {
        public function get_by_date_range($start, $end) {
            return array((object) array(
                'id' => 5, 'service_name' => 'Consultation',
                'start_time_utc' => '2026-11-23 09:00:00',
                'end_time_utc' => '2026-11-23 09:30:00',
                'status' => 'confirmed', 'customer_name' => 'PRIVATE CUSTOMER',
                'customer_email' => 'private@example.org',
            ));
        }
    }
}
namespace {
    define('ABSPATH', __DIR__);
    function __($value, $domain) { return $value; }
    function apply_filters($name, $value) { return $value; }
    function sanitize_text_field($value) { return strip_tags($value); }
    function esc_url_raw($value) { return $value; }
    function absint($value) { return abs((int) $value); }
    function admin_url($url) { return 'https://example.org/wp-admin/' . $url; }
    function post_type_exists($name) { return 'tribe_events' === $name; }
    function get_posts($query) {
        return 'tribe_events' === $query['post_type']
            ? array((object) array('ID' => 12)) : array();
    }
    function get_post_meta($id, $name, $single) {
        return array('_EventStartDateUTC' => '2026-11-24 10:00:00', '_EventEndDateUTC' => '2026-11-24 11:00:00')[$name] ?? '';
    }
    function get_the_title($post) { return 'General assembly'; }
    function get_edit_post_link($id, $context) { return 'https://example.org/wp-admin/post.php?post=' . $id . '&action=edit'; }
    require dirname(__DIR__) . '/includes/class-dendrila-calendar-sources.php';
    $sources = \Dendrila_Calendar_Sources::available();
    if (count($sources) !== 3) throw new \RuntimeException('Missing sources');
    $events = \Dendrila_Calendar_Sources::events('2026-11-22 00:00:00', '2026-11-30 00:00:00', array('oryx','tec'));
    if (count($events) !== 2 || $events[0]['source'] !== 'oryx' || $events[1]['source'] !== 'tec') {
        throw new \RuntimeException('Adapter normalization or ordering failed');
    }
    if (strpos(json_encode($events), 'PRIVATE CUSTOMER') !== false || strpos(json_encode($events), 'private@example.org') !== false) {
        throw new \RuntimeException('Booking personally identifiable information leaked');
    }
    if ($events[0]['start'] !== '2026-11-23T09:00:00Z') {
        throw new \RuntimeException('UTC normalization failed');
    }
    echo "Adapter smoke tests passed\n";
}
