<?php
/** Regression: RFC3339 UTC offsets and Google page tokens must be URL-encoded. */
define('ABSPATH', __DIR__);
final class Dendrila_Calendar_OAuth {
    public static $urls = array();
    public static function calendar_url($item) {
        return 'google' === $item['provider']
            ? 'https://www.googleapis.com/calendar/v3/calendars/test%40example.org/events'
            : 'https://graph.microsoft.com/v1.0/me/calendars/test-id/events';
    }
    public static function api($provider, $access, $method, $url) {
        self::$urls[] = $url;
        parse_str(parse_url($url, PHP_URL_QUERY), $args);
        if ('google' === $provider) {
            if (($args['timeMin'] ?? '') !== '2026-11-19T18:31:19+00:00' ||
                ($args['timeMax'] ?? '') !== '2027-02-19T18:31:19+00:00') {
                return array('status' => 400, 'data' => array());
            }
            if (count(self::$urls) === 1) {
                return array('status' => 200, 'data' => array(
                    'items' => array(array('id' => 'one')), 'nextPageToken' => 'Ab+c/=& x'
                ));
            }
            return (($args['pageToken'] ?? '') === 'Ab+c/=& x')
                ? array('status' => 200, 'data' => array('items' => array(array('id' => 'two'))))
                : array('status' => 400, 'data' => array());
        }
        if (count(self::$urls) === 1) {
            if (($args['startDateTime'] ?? '') !== '2026-11-19T18:31:19+00:00' ||
                ($args['endDateTime'] ?? '') !== '2027-02-19T18:31:19+00:00' ||
                !isset($args['$select'])) {
                return array('status' => 400, 'data' => array());
            }
            return array('status' => 200, 'data' => array(
                'value' => array(array('id' => 'ms-one')),
                '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/calendarView?$skiptoken=ABC%2BDEF'
            ));
        }
        if ($url !== 'https://graph.microsoft.com/v1.0/me/calendarView?$skiptoken=ABC%2BDEF') {
            return array('status' => 400, 'data' => array());
        }
        return array('status' => 200, 'data' => array('value' => array(array('id' => 'ms-two'))));
    }
}
require dirname(__DIR__) . '/includes/class-dendrila-calendar-two-way.php';
$method = new ReflectionMethod('Dendrila_Calendar_Two_Way', 'inventory');
$method->setAccessible(true);
foreach (array('google', 'microsoft') as $provider) {
    Dendrila_Calendar_OAuth::$urls = array();
    $rows = $method->invoke(null, array('provider' => $provider, 'calendar' => 'test@example.org'), 'fake',
        '2026-11-19T18:31:19+00:00', '2027-02-19T18:31:19+00:00');
    if (count($rows) !== 2 || count(Dendrila_Calendar_OAuth::$urls) !== 2 ||
        false === strpos(Dendrila_Calendar_OAuth::$urls[0], '%2B00%3A00')) {
        throw new RuntimeException('Invalid query encoding/pagination for ' . $provider);
    }
}
parse_str('timeMin=2026-11-19T18:31:19+00:00', $bad);
if ($bad['timeMin'] !== '2026-11-19T18:31:19 00:00') {
    throw new RuntimeException('Expected demonstration of unencoded date bug');
}
echo "Google + Microsoft query encoding/pagination: OK\n";
