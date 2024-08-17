<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FlashSale;
use App\Services\Simulation\SimulationRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SimulationController extends Controller
{
    public function __construct(private readonly SimulationRunner $runner) {}

    public function race(Request $request): JsonResponse
    {
        $input = $request->validate([
            'shoppers' => ['required', 'integer', 'min:2', 'max:200'],
            'stock' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->runner->race($input['shoppers'], $input['stock']));
    }

    public function doubleClick(): JsonResponse
    {
        return response()->json($this->runner->doubleClick());
    }

    public function twoTabs(): JsonResponse
    {
        return response()->json($this->runner->twoTabs());
    }

    public function abandonedCart(): JsonResponse
    {
        return response()->json($this->runner->abandonedCart());
    }

    public function outboxStart(): JsonResponse
    {
        return response()->json($this->runner->outboxStart());
    }

    public function outboxState(int $sale): JsonResponse
    {
        return response()->json($this->runner->outboxState($sale));
    }

    public function outboxResume(int $sale): JsonResponse
    {
        return response()->json($this->runner->outboxResume($sale));
    }

    public function burst(Request $request, FlashSale $sale): JsonResponse
    {
        $input = $request->validate(['shoppers' => ['required', 'integer', 'min:1', 'max:100']]);

        return response()->json($this->runner->burst($sale, $input['shoppers']));
    }

    public function reset(FlashSale $sale): JsonResponse
    {
        return response()->json($this->runner->reset($sale));
    }
}
