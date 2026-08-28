<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CatalogueBrowseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'keyword' => 'nullable|string|max:200',
            'search' => 'nullable|string|max:200',
            'min_price' => 'nullable|numeric|min:0|max:999999999',
            'max_price' => ['nullable', 'numeric', 'min:0', 'max:999999999', ...($this->filled('min_price') ? ['gte:min_price'] : [])],
            'category' => 'nullable|integer|min:1',
            'filter' => 'nullable|array',
            'filter.name' => 'nullable|string|max:200',
            'filter.price' => 'nullable|numeric|min:0|max:999999999',
            'filter.price_min' => 'nullable|numeric|min:0|max:999999999',
            'filter.price_max' => 'nullable|numeric|min:0|max:999999999',
            'filter.created_at' => 'nullable|string|max:50',
            'sort' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1|max:1000000',
        ];
    }
}
