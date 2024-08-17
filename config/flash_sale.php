<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Reservation TTL
    |--------------------------------------------------------------------------
    |
    | Seconds a shopper may hold reserved units before they are reclaimed and
    | returned to the available pool. Individual sales may override this.
    |
    */

    'reservation_ttl' => (int) env('FLASH_SALE_RESERVATION_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Reclaim Batch Size
    |--------------------------------------------------------------------------
    |
    | Maximum number of expired reservations reclaimed per sale in a single
    | Lua call. Keeps each script short so Redis is never blocked for long.
    |
    */

    'reclaim_batch' => (int) env('FLASH_SALE_RECLAIM_BATCH', 500),

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    |
    | How long a finished response is kept for replay, and how long a request
    | may stay "in progress" before its key is released for a retry.
    |
    */

    'idempotency' => [
        'ttl' => (int) env('FLASH_SALE_IDEMPOTENCY_TTL', 86_400),
        'lock_ttl' => (int) env('FLASH_SALE_IDEMPOTENCY_LOCK_TTL', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Order Consumer
    |--------------------------------------------------------------------------
    |
    | Redis Stream consumer group that persists confirmed orders to MySQL.
    |
    */

    'consumer' => [
        'group' => env('FLASH_SALE_CONSUMER_GROUP', 'order-persisters'),
        'batch' => (int) env('FLASH_SALE_CONSUMER_BATCH', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Strategy Lab
    |--------------------------------------------------------------------------
    |
    | Simulated per-purchase work (payment authorisation, fraud check...).
    | Every strategy does the same work; what differs is whether the
    | inventory lock is held while it happens.
    |
    */

    'lab_processing_ms' => (int) env('FLASH_SALE_LAB_PROCESSING_MS', 50),

    /*
    |--------------------------------------------------------------------------
    | Broadcasting
    |--------------------------------------------------------------------------
    |
    | Minimum gap between high-frequency WebSocket updates for one sale.
    |
    */

    'broadcast_throttle_ms' => (int) env('FLASH_SALE_BROADCAST_THROTTLE_MS', 200),

    /*
    |--------------------------------------------------------------------------
    | Simulator
    |--------------------------------------------------------------------------
    |
    | The simulator fires real concurrent HTTP requests at the web server, so
    | it needs the URL nginx is reachable on from inside the app container.
    | Disable it outside of demos: its endpoints reset sales.
    |
    */

    'simulation' => [
        'enabled' => (bool) env('FLASH_SALE_SIMULATOR', true),
        'base_url' => env('FLASH_SALE_SIMULATION_URL', 'http://nginx'),
    ],

];
