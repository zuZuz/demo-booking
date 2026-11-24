<?php

use App\Models\Booking;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Monolog\Formatter\JsonFormatter;

$projectDirectory = dirname(__DIR__, 2);

require $projectDirectory.'/vendor/autoload.php';

$app = require $projectDirectory.'/bootstrap/app.php';
$request = Request::capture();
$app->instance('request', $request);
$app->make(Kernel::class)->bootstrap();

$serverDirectory = getenv('BOOKING_CONCURRENCY_DIRECTORY');
config([
    'app.debug' => true,
    'database.default' => 'pgsql',
    'database.connections.pgsql' => json_decode(getenv('BOOKING_CONCURRENCY_DATABASE'), true, flags: JSON_THROW_ON_ERROR),
    'logging.default' => 'null',
    'logging.channels.bookings' => [
        'driver' => 'single',
        'path' => $serverDirectory.'/bookings.log',
        'formatter' => JsonFormatter::class,
        'locking' => true,
    ],
]);
DB::purge('pgsql');

if (! $app->environment('testing') || DB::selectOne('SELECT current_database() AS name')->name !== 'testing') {
    throw new RuntimeException('Concurrent requests must use the testing database.');
}

$firstBookingPath = $serverDirectory.'/first-booking-id';
$firstRequestReadyPath = $serverDirectory.'/first-request-ready';
$waitingPidPath = $serverDirectory.'/waiting-backend-pid';

$waitUntil = static function (Closure $condition, string $message): void {
    $deadline = hrtime(true) + 10_000_000_000;

    while (! $condition()) {
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException($message);
        }

        usleep(10_000);
    }
};

if (getenv('BOOKING_CONCURRENCY_REQUEST') === 'first') {
    Booking::creating(function () use ($firstRequestReadyPath, $waitingPidPath, $waitUntil): void {
        file_put_contents($firstRequestReadyPath, 'ready');

        $waitUntil(
            fn (): bool => is_file($waitingPidPath) && DB::selectOne(
                'SELECT pg_backend_pid() = ANY(pg_blocking_pids(?)) AS blocked',
                [(int) file_get_contents($waitingPidPath)],
            )->blocked,
            'The second booking request did not wait for the room lock.',
        );
    });

    Booking::created(function (Booking $booking) use ($firstBookingPath): void {
        file_put_contents($firstBookingPath, (string) $booking->id);

        if (getenv('BOOKING_CONCURRENCY_FAIL_FIRST') === '1') {
            throw new RuntimeException('Test failure after booking insertion.');
        }
    });
} else {
    $waitUntil(
        fn (): bool => is_file($firstRequestReadyPath),
        'The first booking request did not reach booking creation.',
    );

    file_put_contents($waitingPidPath, (string) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
}

$app->handleRequest($request);
