<?php
/** Two real PHP processes and MariaDB connections contend for each booking profile. */
require __DIR__ . '/booking-lab-bootstrap.php';

if ( '--worker' === ( $argv[1] ?? '' ) ) {
    $input = json_decode( base64_decode( $argv[2] ), true );
    if ( ! empty( $input['hold_lock'] ) ) {
        Dendrila_Calendar_Booking::with_profile_lock( $input['profile'], static function () use ( $input ) {
            file_put_contents( $input['barrier'] . '.ready.' . $input['worker'], 'ready' );
            usleep( 4200000 );
        } );
        echo "{}\n";
        exit;
    }
    if ( ! empty( $input['prewarm'] ) ) {
        $day = ( new DateTimeImmutable( $input['data']['start'] ) )->setTimezone( new DateTimeZone( 'Europe/Paris' ) )->format( 'Y-m-d' );
        Dendrila_Calendar_Schedule::slots( $input['data']['service_id'], $day );
    }
    add_filter( 'wp_insert_post_data', static function ( $data ) {
        if ( Dendrila_Calendar_Native::BOOKING === ( $data['post_type'] ?? '' ) ) { usleep( 250000 ); }
        return $data;
    } );
    file_put_contents( $input['barrier'] . '.ready.' . $input['worker'], 'ready' );
    $deadline = microtime( true ) + 12;
    while ( ! is_file( $input['barrier'] . '.go' ) ) {
        clearstatcache();
        if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'Worker barrier timed out' ); }
        usleep( 10000 );
    }
    $result = Dendrila_Calendar_Booking::create( $input['data'] );
    echo json_encode( is_wp_error( $result ) ? array( 'ok' => false, 'code' => $result->get_error_code() ) :
        array( 'ok' => true, 'booking_id' => $result['booking_id'] ) ) . "\n";
    exit;
}

function lab_worker( $input ) {
    $php = getenv( 'DENDRILA_BOOKING_PHP' ) ?: PHP_BINARY;
    $process = proc_open( array( $php, __FILE__, '--worker', base64_encode( json_encode( $input ) ) ),
        array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
    if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Could not launch PHP concurrency worker' ); }
    fclose( $pipes[0] );
    return array( 'process' => $process, 'pipes' => $pipes, 'input' => $input );
}

function lab_wait_ready( $workers ) {
    $deadline = microtime( true ) + 12;
    do {
        clearstatcache(); $ready = true;
        foreach ( $workers as $worker ) {
            if ( ! is_file( $worker['input']['barrier'] . '.ready.' . $worker['input']['worker'] ) ) { $ready = false; }
        }
        if ( $ready ) { return; }
        if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'Concurrency worker did not reach its barrier' ); }
        usleep( 10000 );
    } while ( true );
}

function lab_collect( $worker ) {
    $output = stream_get_contents( $worker['pipes'][1] );
    $errors = stream_get_contents( $worker['pipes'][2] );
    fclose( $worker['pipes'][1] ); fclose( $worker['pipes'][2] );
    $exit = proc_close( $worker['process'] );
    @unlink( $worker['input']['barrier'] . '.ready.' . $worker['input']['worker'] );
    if ( 0 !== $exit ) { throw new RuntimeException( 'Worker failed: ' . $output . $errors ); }
    $data = json_decode( trim( $output ), true );
    if ( ! is_array( $data ) ) { throw new RuntimeException( 'Worker returned invalid output: ' . $output ); }
    return $data;
}

function lab_race( $first, $second, $prewarm = false ) {
    $barrier = tempnam( sys_get_temp_dir(), 'dendrila-race-' );
    $workers = array();
    foreach ( array( $first, $second ) as $index => $data ) {
        $workers[] = lab_worker( array( 'data' => $data, 'barrier' => $barrier, 'worker' => $index, 'prewarm' => $prewarm ) );
    }
    lab_wait_ready( $workers );
    file_put_contents( $barrier . '.go', 'go' );
    $results = array_map( 'lab_collect', $workers );
    unlink( $barrier . '.go' ); unlink( $barrier );
    // Another PHP process changed posts; the parent must also refresh its query generation.
    wp_cache_set_posts_last_changed();
    return $results;
}

function lab_successes( $results ) {
    return count( array_filter( $results, static function ( $result ) { return $result['ok']; } ) );
}

try {
    $profile = lab_profile( 'Shared resource' );
    $a = lab_service( $profile, 0, 'A' ); $b = lab_service( $profile, 0, 'B' );
    $start = lab_slot( $a );
    $race = lab_race( lab_data( $a, $start ), lab_data( $b, $start ) );
    lab_assert( 1 === lab_successes( $race ), 'Two services concurrently confirmed the same profile and time: ' . json_encode( $race ) );
    lab_assert( in_array( 'dendrila_slot_taken', array_column( $race, 'code' ), true ), 'The losing request did not receive a slot conflict.' );

    $limited = lab_profile( 'Daily quota' );
    $settings = Dendrila_Calendar_Schedule::profile( $limited ); $settings['max_per_day'] = 1;
    update_post_meta( $limited, Dendrila_Calendar_Schedule::PROFILE_META, $settings );
    $service = lab_service( $limited );
    $race = lab_race( lab_data( $service, lab_slot( $service, 0 ) ), lab_data( $service, lab_slot( $service, 2 ) ) );
    lab_assert( 1 === lab_successes( $race ), 'Parallel reservations exceeded the profile daily quota.' );

    $independent = lab_profile( 'Independent resource' );
    $other = lab_service( $independent );
    $new_time = lab_slot( $a );
    $race = lab_race( lab_data( $a, $new_time ), lab_data( $other, $new_time ) );
    lab_assert( 2 === lab_successes( $race ), 'Independent profiles should both accept the same time.' );

    $retry_data = lab_data( $other, lab_slot( $other ) );
    $race = lab_race( $retry_data, $retry_data );
    lab_assert( 2 === lab_successes( $race ) && $race[0]['booking_id'] === $race[1]['booking_id'], 'Simultaneous retries created duplicate bookings.' );

    $shared_request = bin2hex( random_bytes( 16 ) );
    $shared_time = lab_slot( $a, 4 );
    $race = lab_race( lab_data( $a, $shared_time, $shared_request ), lab_data( $other, $shared_time, $shared_request ) );
    lab_assert( 1 === lab_successes( $race ), 'The same request ID created bookings across two profiles.' );
    lab_assert( in_array( 'dendrila_request_reused', array_column( $race, 'code' ), true ), 'Reused request ID did not produce the explicit conflict.' );

    $barrier = tempnam( sys_get_temp_dir(), 'dendrila-lock-' );
    $holder = lab_worker( array( 'hold_lock' => true, 'profile' => $independent, 'barrier' => $barrier, 'worker' => 0 ) );
    lab_wait_ready( array( $holder ) );
    $started = microtime( true );
    $blocked = Dendrila_Calendar_Booking::create( lab_data( $other, lab_slot( $other ) ) );
    $waited = microtime( true ) - $started;
    lab_assert( is_wp_error( $blocked ) && 'dendrila_booking_busy' === $blocked->get_error_code() && $waited >= 2.8 && $waited < 4.1, 'A lock timeout must fail safely after about three seconds.' );
    lab_collect( $holder ); unlink( $barrier );
    try {
        Dendrila_Calendar_Booking::with_profile_lock( $independent, static function () { throw new RuntimeException( 'intentional-lock-test' ); } );
    } catch ( RuntimeException $error ) { if ( 'intentional-lock-test' !== $error->getMessage() ) { throw $error; } }
    lab_assert( true === Dendrila_Calendar_Booking::with_profile_lock( $independent, static function () { return true; } ), 'An exception left the profile locked.' );

    // A prior availability read in either PHP process must not defeat the recheck under lock.
    $cached = lab_profile( 'Cached availability' );
    $cached_a = lab_service( $cached, 0, 'Cached A' ); $cached_b = lab_service( $cached, 0, 'Cached B' );
    $cached_time = lab_slot( $cached_a );
    $race = lab_race( lab_data( $cached_a, $cached_time ), lab_data( $cached_b, $cached_time ), true );
    lab_assert( 1 === lab_successes( $race ), 'Availability cached before locking allowed two confirmations: ' . json_encode( $race ) );
    echo $GLOBALS['lab_checks'] . " real MariaDB/PHP concurrency checks passed: shared slots, quota, independent profiles, idempotency across profiles, timeout, exception and cached reads\n";
} finally {
    lab_cleanup();
}
