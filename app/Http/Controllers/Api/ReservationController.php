<?php

namespace App\Http\Controllers\Api;

use App\DTOs\ReservationResultData;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveShopper;
use App\Http\Requests\ReserveRequest;
use App\Services\FlashSale\FlashSaleRepository;
use App\Services\FlashSale\InventoryLockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReservationController extends Controller
{
    public function __construct(
        private readonly FlashSaleRepository $sales,
        private readonly InventoryLockService $inventory,
    ) {}

    public function store(ReserveRequest $request, int $sale): JsonResponse
    {
        $flashSale = $this->sales->find($sale);

        if (! $flashSale->isLive()) {
            return response()->json(['status' => 'sale_closed', 'message' => 'This sale is not running.'], 422);
        }

        return $this->respond($this->inventory->reserve($flashSale, $this->shopper($request), $request->quantity()));
    }

    public function confirm(Request $request, int $sale, string $token): JsonResponse
    {
        return $this->respond($this->inventory->confirm($this->sales->find($sale), $this->shopper($request), $token));
    }

    public function destroy(Request $request, int $sale, string $token): JsonResponse
    {
        return $this->respond($this->inventory->release($this->sales->find($sale), $this->shopper($request), $token));
    }

    private function shopper(Request $request): int
    {
        return $request->attributes->get(ResolveShopper::ATTRIBUTE);
    }

    private function respond(ReservationResultData $result): JsonResponse
    {
        return response()->json($result->toArray(), $result->status->httpStatus());
    }
}
