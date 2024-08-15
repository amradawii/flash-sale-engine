<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReserveRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ];
    }

    public function quantity(): int
    {
        return (int) $this->input('quantity', 1);
    }
}
