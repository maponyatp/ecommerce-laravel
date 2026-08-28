<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantDraft;
use App\Models\User;
use App\Support\StoreMoney;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductVariantDraftService
{
    public function values(?ProductVariantDraft $draft): ?array
    {
        return $draft?->only(['sku', 'title', 'options', 'price', 'currency', 'archived']);
    }

    public function save(Product $product, ?ProductVariantDraft $draft, array $input, User $actor): ProductVariantDraft
    {
        $this->authorize($product, $actor);
        if (is_string($input['sku'] ?? null)) {
            $input['sku'] = strtoupper(trim($input['sku']));
        }
        $validator = Validator::make($input, [
            'version' => 'required|integer|min:0',
            'sku' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/', Rule::unique('product_variant_drafts', 'sku')->ignore($draft?->id)],
            'title' => 'required|string|max:120',
            'options' => 'required|array|min:1|max:3',
            'options.*' => 'required|string|max:80',
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            'archived' => 'required|boolean',
        ]);
        if ($validator->fails()) {
            $errors = [];
            foreach ($validator->errors()->toArray() as $field => $messages) {
                $visibleField = str_starts_with($field, 'options.') ? 'options' : $field;
                $errors[$visibleField] = array_merge($errors[$visibleField] ?? [], $messages);
            }
            throw ValidationException::withMessages($errors);
        }
        $data = $validator->validated();
        $options = [];
        $seen = [];
        foreach ($data['options'] as $key => $value) {
            if (! is_string($key) || ! preg_match('/^[\pL\pN][\pL\pN _-]{0,39}$/u', trim($key))
                || isset($seen[mb_strtolower(trim($key))]) || trim($value) === '') {
                throw ValidationException::withMessages(['options' => 'Use up to three distinct option names (40 characters each) and non-empty values (80 characters each).']);
            }
            $seen[mb_strtolower(trim($key))] = true;
            $options[trim($key)] = trim($value);
        }
        ksort($options, SORT_STRING);
        $values = ['sku' => $data['sku'], 'title' => trim($data['title']), 'options' => $options,
            'price' => number_format((float) $data['price'], 2, '.', ''), 'archived' => (bool) $data['archived']];
        if ($values['title'] === '') {
            throw ValidationException::withMessages(['title' => 'Enter a draft title.']);
        }
        try {
            return DB::transaction(function () use ($product, $draft, $data, $values, $actor) {
                $product = Product::lockForUpdate()->findOrFail($product->id);
                $this->authorize($product, $actor);
                if ($product->is_downloadable || ! in_array($product->pricing_type, [null, 'fixed'], true)) {
                    throw ValidationException::withMessages(['title' => 'Draft variants currently support physical, fixed-price products only.']);
                }
                $record = $draft ? ProductVariantDraft::where('product_id', $product->id)->lockForUpdate()->findOrFail($draft->id) : null;
                if (($record?->version ?? 0) !== (int) $data['version']) {
                    throw ValidationException::withMessages(['title' => 'Another staff member changed this draft. Close the editor and reopen it before saving.']);
                }
                if (! $record && ProductVariantDraft::where('product_id', $product->id)->count() >= 100) {
                    throw ValidationException::withMessages(['title' => 'A product can have at most 100 variant drafts, including archived drafts.']);
                }
                if ($record && $record->currency !== StoreMoney::currency()) {
                    throw ValidationException::withMessages(['price' => 'The store currency has changed. This draft keeps its original currency; currency migration requires review.']);
                }
                $published = $record ? ProductVariant::where('draft_id', $record->id)->first() : null;
                if ($published && ($values['sku'] !== $published->sku || $values['options'] !== $published->options)) {
                    throw ValidationException::withMessages(['sku' => 'Published SKU/options identify physical stock. Create a new draft to sell a different option.']);
                }
                $before = $this->values($record);
                $values['currency'] = $record?->currency ?? StoreMoney::currency();
                // Stable field ordering allows unchanged saves to remain audit no-ops.
                $after = array_replace(array_fill_keys(['sku', 'title', 'options', 'price', 'currency', 'archived'], null), $values);
                if ($before === $after) {
                    return $record;
                }
                $record ??= new ProductVariantDraft(['product_id' => $product->id, 'version' => 0]);
                $record->fill($after);
                $record->version++;
                $record->save();
                $record->changes()->create(['actor_id' => $actor->id, 'version' => $record->version,
                    'before_values' => $before, 'after_values' => $after]);

                return $record->fresh();
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (ProductVariantDraft::where('sku', $data['sku'])->when($draft, fn ($query) => $query->whereKeyNot($draft->id))->exists()) {
                throw ValidationException::withMessages(['sku' => 'That proposed SKU is already reserved by another draft.']);
            }
            throw $exception;
        }
    }

    private function authorize(Product $product, User $actor): void
    {
        abort_unless($actor->hasAnyRole(['admin', 'super_admin']), 403);
        Gate::forUser($actor)->authorize('viewAny', Product::class);
        Gate::forUser($actor)->authorize('view', $product);
        Gate::forUser($actor)->authorize('update', $product);
    }
}
