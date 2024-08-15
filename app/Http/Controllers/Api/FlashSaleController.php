<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FlashSale;
use App\Services\FlashSale\InventoryLockService;
use Illuminate\Http\JsonResponse;

class FlashSaleController extends Controller
{
    public function __construct(private readonly InventoryLockService $inventory) {}

    public function index(): JsonResponse
    {
        $sales = FlashSale::query()
            ->with('product')
            ->where('ends_at', '>', now())
            ->whereHas('product', fn ($q) => $q->where('slug', 'not like', 'lab-%'))
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $sales->map(fn (FlashSale $sale) => $this->present($sale))->all(),
            'server_time' => now()->timestamp,
        ]);
    }

    public function show(FlashSale $sale): JsonResponse
    {
        $sale->load('product')->loadCount('orders');

        return response()->json([
            'data' => $this->present($sale) + [
                'database' => [
                    'stock_remaining' => $sale->stock_remaining,
                    'orders' => $sale->orders_count,
                    'units_sold' => (int) $sale->orders()->sum('quantity'),
                ],
                'reservations' => $this->inventory->reservations($sale->id),
            ],
            'server_time' => now()->timestamp,
        ]);
    }

    private function present(FlashSale $sale): array
    {
        return [
            'id' => $sale->id,
            'name' => $sale->name,
            'product' => [
                'name' => $sale->product->name,
                'description' => $sale->product->description,
                'price_cents' => $sale->product->price_cents,
            ],
            'sale_price_cents' => $sale->sale_price_cents,
            'stock_total' => $sale->stock_total,
            'max_per_user' => $sale->max_per_user,
            'reservation_ttl_seconds' => $sale->reservation_ttl_seconds,
            'starts_at' => $sale->starts_at->timestamp,
            'ends_at' => $sale->ends_at->timestamp,
            'live' => $sale->isLive(),
            'redis' => $this->inventory->snapshot($sale->id),
        ];
    }
}
