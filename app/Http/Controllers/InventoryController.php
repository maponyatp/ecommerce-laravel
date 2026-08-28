<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\InventoryManagementService;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function adjustInventory(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'quantity_change' => 'required|integer|between:-2147483647,2147483647',
            'reason' => 'required|string|max:255',
        ]);
        $product = Product::findOrFail($data['product_id']);
        $product = app(InventoryManagementService::class)->adjust($product, null, [
            'mode' => 'adjust', 'quantity' => $data['quantity_change'], 'reason' => $data['reason'],
            'expected_quantity' => $product->inventory_count,
        ], $request->user());

        return response()->json(['message' => 'Inventory adjusted successfully', 'product' => $product]);
    }
}
