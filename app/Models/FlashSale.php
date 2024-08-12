<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlashSale extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'name',
        'sale_price_cents',
        'stock_total',
        'stock_remaining',
        'max_per_user',
        'reservation_ttl_seconds',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'sale_price_cents' => 'integer',
            'stock_total' => 'integer',
            'stock_remaining' => 'integer',
            'max_per_user' => 'integer',
            'reservation_ttl_seconds' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(FlashSaleOrder::class);
    }

    public function scopeLive(Builder $query): void
    {
        $query->where('starts_at', '<=', now())->where('ends_at', '>', now());
    }

    public function isLive(): bool
    {
        return $this->starts_at->isPast() && $this->ends_at->isFuture();
    }
}
