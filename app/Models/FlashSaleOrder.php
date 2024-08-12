<?php

namespace App\Models;

use App\Enums\SaleStrategy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlashSaleOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'flash_sale_id',
        'user_id',
        'reservation_token',
        'quantity',
        'unit_price_cents',
        'strategy',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_cents' => 'integer',
            'strategy' => SaleStrategy::class,
            'confirmed_at' => 'datetime',
        ];
    }

    public function flashSale(): BelongsTo
    {
        return $this->belongsTo(FlashSale::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
