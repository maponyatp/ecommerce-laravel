<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\CartService;
use App\Services\CouponService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;

class CartController extends Controller
{
    public function add(Request $request, Product $product)
    {
        return $this->mutate(fn () => app(CartService::class)->add($product->id, $request->input('quantity', 1), $request->input('variant_id')), 'Product added to cart successfully!');
    }

    public function index()
    {
        return view('cart.index');
    }

    public function update(Request $request, $productId)
    {
        return $this->mutate(fn () => app(CartService::class)->update($productId, $request->input('quantity', 1)), 'Cart updated successfully!');
    }

    public function remove($productId)
    {
        return $this->mutate(fn () => app(CartService::class)->remove($productId), 'Product removed from cart successfully!');
    }

    public function clear()
    {
        return $this->mutate(fn () => app(CartService::class)->clear(), 'Cart cleared successfully!');
    }

    private function mutate(callable $operation, string $message)
    {
        try {
            $operation();

            return redirect()->back()->with('success', $message);
        } catch (ValidationException $exception) {
            return redirect()->back()->with('error', $exception->validator->errors()->first());
        }
    }

    public function applyCoupon(Request $request, CouponService $couponService)
    {
        $request->validate(['coupon_code' => 'required|string|max:255']);

        return $this->mutate(function () use ($request, $couponService) {
            $cartService = app(CartService::class);
            $cart = $cartService->hydrate($cartService->current());
            if ($cart === []) {
                throw ValidationException::withMessages(['cart' => 'Your cart is empty.']);
            }
            $subtotal = collect($cart)->sum(fn ($item) => $item['price'] * $item['quantity']);
            $result = $couponService->validateAndApplyCoupon($request->coupon_code, $subtotal);
            if (! $result['valid']) {
                throw ValidationException::withMessages(['coupon' => $result['error']]);
            }
            Session::put('coupon', ['code' => $request->coupon_code, 'discount' => $result['discount'], 'coupon_id' => $result['coupon']->id]);
            Session::forget('checkout_quote');
        }, 'Coupon applied successfully!');
    }

    public function removeCoupon()
    {
        Session::forget(['coupon', 'checkout_quote']);

        return redirect()->back()->with('success', 'Coupon removed successfully!');
    }
}
