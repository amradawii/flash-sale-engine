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

];
