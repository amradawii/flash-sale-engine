<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('flash-sale:reclaim-locks')
    ->everySecond()
    ->withoutOverlapping(1);
