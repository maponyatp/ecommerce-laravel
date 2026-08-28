<?php

/**
 * HomeController handles the requests for the home page of the ecommerce platform.
 * It fetches and passes featured products, latest products, and special offers to the home view.
 */

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Settings\GeneralSettings;
use Illuminate\View\View;

class HomeController extends Controller
{
    /**
     * Displays the home page with featured products, latest products, and special offers.
     *
     * @return View The home view populated with products and offers.
     */
    public function index()
    {
        $settings = app(GeneralSettings::class);
        $featuredProducts = Product::where('is_featured', true)->take($settings->homepage_product_limit)->get();
        $latestProducts = Product::latest()->take($settings->homepage_product_limit)->get();
        $featuredCategories = ProductCategory::query()
            ->whereNull('parent_category_id')
            ->orderBy('name')
            ->take($settings->homepage_category_limit)
            ->get();
        // Assuming 'specialOffers' is a method or scope on the Product model that retrieves special offers
        // $specialOffers = Product::specialOffers()->get();

        return view('home', [
            'featuredProducts' => $featuredProducts,
            'latestProducts' => $latestProducts,
            'specialOffers' => [], // $specialOffers,
            'featuredCategories' => $featuredCategories,
        ]);
    }
}
