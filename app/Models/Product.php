<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'image_url',
        'price_cents',
    ];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
        ];
    }

    public function flashSales(): HasMany
    {
        return $this->hasMany(FlashSale::class);
    }
}
