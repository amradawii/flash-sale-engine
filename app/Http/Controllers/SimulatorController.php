<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class SimulatorController extends Controller
{
    public function __invoke(): View
    {
        $lua = collect(['reserve', 'confirm', 'release', 'reclaim'])
            ->mapWithKeys(fn (string $name) => [
                $name => file_get_contents(app_path("Support/Redis/LuaScripts/{$name}.lua")),
            ]);

        return view('simulator', [
            'lua' => $lua,
            'processingMs' => config('flash_sale.lab_processing_ms'),
            'simulatorEnabled' => config('flash_sale.simulation.enabled'),
        ]);
    }
}
