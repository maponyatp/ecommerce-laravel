<?php

namespace App\Http\Controllers;

use App\Http\Middleware\PrivateCustomerDirectory;
use App\Models\Product;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WishlistController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth')->except('sharedWishlist');
        $this->middleware(PrivateCustomerDirectory::class);
    }

    public function index()
    {
        // Owners can remove retired products without making those products public.
        $wishlist = auth()->user()->wishlist()->with(['product' => fn ($query) => $query->withTrashed()])->get();

        return view('wishlist.index', compact('wishlist'));
    }

    public function add(Product $product)
    {
        $wishlist = Wishlist::firstOrCreate([
            'user_id' => auth()->id(),
            'product_id' => $product->id,
        ]);

        return redirect()->back()->with('success', 'Product added to wishlist');
    }

    public function remove(string $product)
    {
        auth()->user()->wishlist()->whereHas('product', fn ($query) => $query->withTrashed()->where('slug', $product))
            ->firstOrFail()->delete();

        return redirect()->back()->with('success', 'Product removed from wishlist');
    }

    public function share()
    {
        $shareToken = Str::random(32);
        DB::transaction(function () use ($shareToken) {
            User::whereKey(auth()->id())->lockForUpdate()->firstOrFail();
            auth()->user()->wishlist()->update(['share_token' => $shareToken]);
        }, 3);

        return redirect()->route('wishlist.index')->with('share_url', route('wishlist.shared', $shareToken));
    }

    public function sharedWishlist($shareToken)
    {
        $wishlist = Wishlist::where('share_token', $shareToken)->whereHas('product')->with('product')->get();

        return view('wishlist.shared', compact('wishlist'));
    }
}
