<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidationValidator;

class ProductController extends Controller
{
    /**
     * Display a paginated listing of products.
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'per_page' => 'sometimes|integer|min:1|max:100',
            'page' => 'sometimes|integer|min:1',
            'search' => 'sometimes|string|max:255',
            'category_id' => 'sometimes|integer|exists:product_categories,id',
            'price_min' => 'sometimes|numeric|decimal:0,2|min:0|max:99999999.99',
            'price_max' => 'sometimes|numeric|decimal:0,2|min:0|max:99999999.99',
            'sort_by' => 'sometimes|in:name,price,created_at,updated_at',
            'sort_order' => 'sometimes|in:asc,desc',
        ]);
        $validator->after(function (ValidationValidator $validator) use ($request): void {
            $priceMin = $request->input('price_min');
            $priceMax = $request->input('price_max');
            if (is_numeric($priceMin) && is_numeric($priceMax) && (float) $priceMax < (float) $priceMin) {
                $validator->errors()->add('price_max', 'The maximum price must be greater than or equal to the minimum price.');
            }
        });
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }
        $filters = $validator->validated();
        $perPage = (int) ($filters['per_page'] ?? 15);

        $query = Product::query();

        // Apply search filter if provided
        if (array_key_exists('search', $filters)) {
            $search = $filters['search'];
            // Sanitize search input to prevent SQL injection
            $search = strip_tags($search);
            $search = preg_replace('/[^\w\s\-]/', '', $search);

            if (! empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', '%'.$search.'%')
                        ->orWhere('description', 'like', '%'.$search.'%')
                        ->orWhere('short_description', 'like', '%'.$search.'%');
                });
            }
        }

        // Apply category filter if provided
        if (array_key_exists('category_id', $filters)) {
            $query->where('category_id', $filters['category_id']);
        }

        // Apply price range filters
        if (array_key_exists('price_min', $filters)) {
            $query->priceMin($filters['price_min']);
        }

        if (array_key_exists('price_max', $filters)) {
            $query->priceMax($filters['price_max']);
        }

        // Apply sorting
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        if ($sortBy === 'price') {
            $query->orderByStorePrice($sortOrder === 'desc');
        } else {
            $query->orderBy($sortBy, $sortOrder);
        }

        $products = $query->with(['category', 'images'])->paginate($perPage);
        $products->getCollection()->each(fn (Product $product) => $product->setAttribute('effective_price', $product->base_store_price));

        return response()->json($products);
    }

    /**
     * Display the specified product by ID or slug.
     */
    public function show(string $identifier): JsonResponse
    {
        $numericId = $this->numericId($identifier);

        // Try to find by ID if identifier is numeric, otherwise by slug
        $product = $numericId !== null
            ? Product::with(['category', 'images', 'variants', 'tags', 'review', 'rating'])
                ->find($numericId)
            : Product::with(['category', 'images', 'variants', 'tags', 'review', 'rating'])
                ->where('slug', $identifier)
                ->first();

        if (! $product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $product,
        ]);
    }

    /**
     * Store a newly created product.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:65535',
            'short_description' => 'required|string|max:65535',
            'long_description' => 'nullable|string|max:65535',
            'price' => 'exclude_if:pricing_type,free|required|numeric|decimal:0,2|min:0|max:99999999.99',
            'category_id' => 'required|exists:product_categories,id',
            'featured_image' => ['nullable', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/'],
            'inventory_count' => 'nullable|integer|min:0|max:2147483647',
            'low_stock_threshold' => 'nullable|integer|min:0|max:2147483647',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:65535',
            'meta_keywords' => 'nullable|string|max:65535',
            'is_downloadable' => 'prohibited',
            'downloadable_file' => 'prohibited',
            'download_limit' => 'nullable|integer|min:1|max:10000',
            'expiration_time' => 'nullable|date_format:Y-m-d\\TH:i:sP|after:now',
            'pricing_type' => 'nullable|string|in:fixed,free',
            'suggested_price' => 'prohibited',
            'minimum_price' => 'prohibited',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $product = Product::create($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Product created successfully',
            'data' => $product->load(['category', 'images']),
        ], 201);
    }

    /**
     * Update the specified product.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $product = ($numericId = $this->numericId($id)) === null ? null : Product::find($numericId);

        if (! $product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:65535',
            'short_description' => 'sometimes|required|string|max:65535',
            'long_description' => 'nullable|string|max:65535',
            'price' => 'sometimes|exclude_if:pricing_type,free|required|numeric|decimal:0,2|min:0|max:99999999.99',
            'category_id' => 'sometimes|nullable|exists:product_categories,id',
            'featured_image' => ['nullable', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/'],
            'inventory_count' => 'nullable|integer|min:0|max:2147483647',
            'low_stock_threshold' => 'nullable|integer|min:0|max:2147483647',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:65535',
            'meta_keywords' => 'nullable|string|max:65535',
            'is_downloadable' => 'prohibited',
            'downloadable_file' => 'prohibited',
            'download_limit' => 'nullable|integer|min:1|max:10000',
            'expiration_time' => 'nullable|date_format:Y-m-d\\TH:i:sP|after:now',
            'pricing_type' => 'nullable|string|in:fixed,free',
            'suggested_price' => 'prohibited',
            'minimum_price' => 'prohibited',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->exists('inventory_count')) {
            return response()->json(['success' => false, 'errors' => ['inventory_count' => ['Use the dedicated inventory adjustment workflow; product edits cannot overwrite live stock.']]], 422);
        }

        $product->update($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully',
            'data' => $product->load(['category', 'images']),
        ]);
    }

    /**
     * Soft delete the specified product.
     */
    public function destroy(string $id): JsonResponse
    {
        $product = ($numericId = $this->numericId($id)) === null ? null : Product::find($numericId);

        if (! $product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found',
            ], 404);
        }

        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully',
        ]);
    }

    private function numericId(string $identifier): ?int
    {
        $id = filter_var($identifier, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }
}
