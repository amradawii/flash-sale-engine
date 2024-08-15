<?php

namespace App\Services\FlashSale\Strategies;

trait SimulatesOrderProcessing
{
    /** Stand-in for payment authorisation / fraud checks done per order. */
    protected function processOrder(): void
    {
        usleep(config('flash_sale.lab_processing_ms') * 1000);
    }
}
