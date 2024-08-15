<?php

namespace App\Http\Controllers\Api;

use App\Enums\SaleStrategy;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveShopper;
use App\Http\Requests\ReserveRequest;
use App\Services\FlashSale\FlashSaleRepository;
use App\Services\FlashSale\Strategies\StrategyResolver;
use Illuminate\Http\JsonResponse;

/**
 * Runs one full purchase with the chosen strategy so the three approaches can
 * be compared under the same concurrent load.
 */
class StrategyLabController extends Controller
{
    public function __invoke(
        ReserveRequest $request,
        int $sale,
        SaleStrategy $strategy,
        FlashSaleRepository $sales,
        StrategyResolver $strategies,
    ): JsonResponse {
        $startedAt = hrtime(true);

        $result = $strategies->resolve($strategy)->purchase(
            $sales->find($sale),
            $request->attributes->get(ResolveShopper::ATTRIBUTE),
            $request->quantity(),
        );

        return response()->json($result->toArray() + [
            'strategy' => $strategy->value,
            'server_ms' => round((hrtime(true) - $startedAt) / 1e6, 2),
        ], $result->status->httpStatus());
    }
}
