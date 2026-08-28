<?php

use App\Http\Controllers\CartController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CmsPageController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\Frontend\ProductCategoryController;
use App\Http\Controllers\Frontend\ProductCollectionController;
use App\Http\Controllers\Frontend\ProductController;
use App\Http\Controllers\Frontend\ProductTagController;
use App\Http\Controllers\FulfillmentDocumentController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\IkhokhaPaymentController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\OrderHistoryController;
use App\Http\Controllers\OrderSupportController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\RatingController;
use App\Http\Controllers\ReviewController; // New controller for cart
use App\Http\Controllers\ShippingController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SiteSettingController;
use App\Http\Controllers\WishlistController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use JoelButcher\Socialstream\Socialstream;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

// Kubernetes liveness/readiness probe endpoint
Route::get('/health', function () {
    try {
        DB::connection()->getPdo();

        return response()->json(['status' => 'ok', 'db' => 'connected'], 200);
    } catch (Throwable $e) {
        return response()->json(['status' => 'degraded', 'db' => 'unavailable'], 503);
    }
})->name('health');

Route::get('/', [HomeController::class, 'index'])->name('home');

// Product routes
Route::get('/wishlist/shared/{shareToken}', [WishlistController::class, 'sharedWishlist'])->name('wishlist.shared');
Route::middleware('auth')->group(function () {
    Route::get('/wishlist', [WishlistController::class, 'index'])->name('wishlist.index');
    Route::post('/wishlist/add/{product}', [WishlistController::class, 'add'])->name('wishlist.add');
    Route::delete('/wishlist/remove/{product}', [WishlistController::class, 'remove'])->name('wishlist.remove');
    Route::post('/wishlist/share', [WishlistController::class, 'share'])->name('wishlist.share');
});
Route::get('/products', [ProductController::class, 'index'])->name('products.index');
Route::get('/products/search', [ProductController::class, 'search'])->name('products.search');
Route::get('/products/compare', [\App\Http\Controllers\ProductComparisonController::class, 'index'])->name('products.compare');
Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');

// Category routes
Route::get('/categories', [ProductCategoryController::class, 'index'])->name('categories.index');
Route::get('/categories/{category}', [ProductCategoryController::class, 'show'])->name('categories.show');
Route::get('/categories/{category}/products', [ProductCategoryController::class, 'products'])->name('categories.products');

// Collection routes
Route::get('/collections', [ProductCollectionController::class, 'index'])->name('collections.index');
Route::get('/collections/{collection}', [ProductCollectionController::class, 'show'])->name('collections.show');
Route::get('/collections/{collection}/products', [ProductCollectionController::class, 'products'])->name('collections.products');

// Tag routes
Route::get('/tags', [ProductTagController::class, 'index'])->name('tags.index');
Route::get('/tags/{tag}', [ProductTagController::class, 'show'])->name('tags.show');

// Checkout routes
Route::get('/checkout', [CheckoutController::class, 'initiateCheckout'])->block(90, 10)->name('checkout.initiate');
Route::post('/checkout/process', [CheckoutController::class, 'processCheckout'])->block(90, 10)->name('checkout.process');
Route::post('/checkout/quote', [CheckoutController::class, 'quote'])->block(90, 10)->middleware('throttle:60,1')->name('checkout.quote');
Route::get('/checkout/confirmation/{order}', [CheckoutController::class, 'showConfirmation'])
    ->middleware('signed')
    ->name('checkout.confirmation');
Route::post('/payments/ikhokha/webhook', [IkhokhaPaymentController::class, 'webhook'])
    ->middleware('throttle:120,1')
    ->name('payments.ikhokha.webhook');
Route::get('/payments/ikhokha/return/{order}', [IkhokhaPaymentController::class, 'return'])
    ->middleware('signed')
    ->name('payments.ikhokha.return');

Route::get('/order-support/{order}', [OrderSupportController::class, 'show'])
    ->middleware('throttle:60,1')->name('order-support.show');
Route::post('/order-support/{order}', [OrderSupportController::class, 'store'])
    ->middleware('throttle:6,1')->block(30, 10)->name('order-support.store');

// Shipping routes
Route::middleware(['auth', 'admin', 'throttle:60,1'])->group(function () {
    Route::get('/operations/packing-slips/{order}', [FulfillmentDocumentController::class, 'packingSlip'])->name('operations.packing-slip');
    Route::get('/operations/delivery-list', [FulfillmentDocumentController::class, 'deliveryList'])->name('operations.delivery-list');
});

Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('/shipping', [ShippingController::class, 'index'])->name('shipping.index');
    Route::post('/shipping', [ShippingController::class, 'store'])->name('shipping.store');
    Route::put('/shipping/{shippingMethod}', [ShippingController::class, 'update'])->name('shipping.update');
    Route::delete('/shipping/{shippingMethod}', [ShippingController::class, 'destroy'])->name('shipping.destroy');
});
Route::get('/invoice/{invoice}/print', [InvoiceController::class, 'print'])
    ->middleware('signed')
    ->name('invoices.print');

// Order history routes
Route::middleware('auth')->group(function () {
    Route::get('/orders', [OrderHistoryController::class, 'index'])->name('orders.index');
    Route::get('/orders/{id}', [OrderHistoryController::class, 'show'])->name('orders.show');
});

Route::middleware('auth')->prefix('payment_methods')->group(function () {
    Route::get('/', [PaymentMethodController::class, 'index'])->name('payment_methods.index');
    Route::post('/store', [PaymentMethodController::class, 'addPaymentMethod'])->name('payment_methods.store');
    Route::get('/edit/{id}', [PaymentMethodController::class, 'editPaymentMethod'])->name('payment_methods.edit');
    Route::post('/update/{id}', [PaymentMethodController::class, 'editPaymentMethod'])->name('payment_methods.update');
    Route::delete('/destroy/{id}', [PaymentMethodController::class, 'deletePaymentMethod'])->name('payment_methods.destroy');
    Route::post('/set_default/{id}', [PaymentMethodController::class, 'setDefaultPaymentMethod'])->name('payment_methods.setDefault');
});

// Payment capture is handled only by the checkout flow. Do not expose
// customer-supplied amounts or unverified gateway identifiers as public endpoints.

// Cart routes
Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
Route::post('/cart/add/{product}', [CartController::class, 'add'])->name('cart.add');
Route::put('/cart/update/{productId}', [CartController::class, 'update'])->name('cart.update');
Route::delete('/cart/remove/{productId}', [CartController::class, 'remove'])->name('cart.remove');
Route::delete('/cart/clear', [CartController::class, 'clear'])->name('cart.clear');
Route::post('/cart/apply-coupon', [CartController::class, 'applyCoupon'])->name('cart.apply-coupon');
Route::delete('/cart/remove-coupon', [CartController::class, 'removeCoupon'])->name('cart.remove-coupon');

// Ratings and reviews
Route::get('/product/{product}/reviews', [ReviewController::class, 'show'])->name('reviews.show');
Route::post('/reviews', [ReviewController::class, 'store'])->name('reviews.store');
Route::post('/reviews/approve/{id}', [ReviewController::class, 'approve'])->middleware(['auth', 'admin'])->name('reviews.approve');
Route::post('/reviews/{id}/vote', [ReviewController::class, 'vote'])->middleware(['auth', 'throttle:30,1'])->name('reviews.vote');

Route::get('/product/{product}/ratings/average', [RatingController::class, 'calculateAverageRating'])->name('ratings.average');
Route::post('/ratings', [RatingController::class, 'store'])->middleware('auth')->name('ratings.store');

// New comparison routes
Route::post('/product/{category}/{product}/compare', [\App\Http\Controllers\ProductComparisonController::class, 'add'])->block(10, 5)->name('products.addToCompare');
Route::delete('/product/{category}/{product}/compare', [\App\Http\Controllers\ProductComparisonController::class, 'remove'])->block(10, 5)->name('products.removeFromCompare');
Route::delete('/products/compare/clear', [\App\Http\Controllers\ProductComparisonController::class, 'clear'])->block(10, 5)->name('products.clearCompare');

Route::get('/downloads/order-items/{item}', [DownloadController::class, 'download'])
    ->middleware(['signed', 'throttle:30,1'])->name('downloads.file');

Route::middleware('auth')->group(function () {
    Route::get('/download/{category}/{product}', [DownloadController::class, 'generateSecureLink'])->name('download.generate-link');
    Route::get('/download/file/{category}/{product}', [DownloadController::class, 'serveFile'])->name('download.serve-file');

    // Invoice routes
    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/{id}', [InvoiceController::class, 'show'])->name('invoices.show');
});

Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('/site-settings', [SiteSettingController::class, 'index'])->name('site_settings.index');
    Route::get('/site-settings/{id}/edit', [SiteSettingController::class, 'edit'])->name('site_settings.edit');
    Route::post('/site-settings/{id}', [SiteSettingController::class, 'update'])->name('site_settings.update');
    Route::post('/inventory/adjust', [InventoryController::class, 'adjustInventory'])->name('inventory.adjust');
});

Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap.xml');

Route::post('/contact', [ContactController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('contact.submit');

// CMS pages. Core storefront routes remain above this catch-all route.
Route::middleware(['auth', 'admin', \App\Http\Middleware\PrivateCustomerDirectory::class, 'throttle:20,1'])->group(function () {
    Route::get('/admin/theme-library/export/{theme?}', [\App\Http\Controllers\ThemeLibraryController::class, 'export'])->whereNumber('theme')->name('themes.export');
    Route::get('/admin/theme-library/preview/{theme}', [\App\Http\Controllers\ThemeLibraryController::class, 'preview'])->whereNumber('theme')->middleware('signed')->name('themes.preview');
    Route::get('/admin/theme-library/assets/{theme}/{file}', [\App\Http\Controllers\ThemeLibraryController::class, 'asset'])->whereNumber('theme')->name('themes.asset');
});
Route::get('/credit-notes/{creditNote}', [\App\Http\Controllers\CreditNoteController::class, 'show'])
    ->whereNumber('creditNote')->middleware([\App\Http\Middleware\PrivateCustomerDirectory::class, 'throttle:60,1'])->name('credit-notes.show');
Route::get('/operations/refunds/export', [\App\Http\Controllers\CreditNoteController::class, 'export'])
    ->middleware(['auth', 'admin', \App\Http\Middleware\PrivateCustomerDirectory::class, 'throttle:10,1'])->name('operations.refunds.export');
Route::get('/cms/preview/{page:id}/{version}', [CmsPageController::class, 'preview'])
    ->whereNumber('page')->whereNumber('version')
    ->middleware(['auth', 'signed', \App\Http\Middleware\PrivateCustomerDirectory::class, 'throttle:60,1'])
    ->name('cms.pages.preview');
Route::get('/pages/{slug}', [CmsPageController::class, 'show'])->name('cms.pages.show');

// Preserve existing named storefront URLs while allowing a published CMS page
// with the same slug to replace the legacy view.
Route::get('/about', [CmsPageController::class, 'show'])->defaults('slug', 'about')->name('about');
Route::get('/contact', [CmsPageController::class, 'show'])->defaults('slug', 'contact')->name('contact');
Route::get('/shop', [CmsPageController::class, 'show'])->defaults('slug', 'shop')->name('shop');

Route::view('/account', 'account')->middleware('auth')->name('account');

// Blog routes

// Chat routes
Route::prefix('chat')->middleware([\App\Http\Middleware\PrivateCustomerDirectory::class, 'throttle:120,1'])->group(function () {
    Route::post('/start', [ChatController::class, 'start'])->name('chat.start');
    Route::get('/session/{sessionId}', [ChatController::class, 'getBySession'])->name('chat.session');
    Route::post('/{conversationId}/message', [ChatController::class, 'sendMessage'])->name('chat.message');
    Route::get('/{conversationId}/messages', [ChatController::class, 'getMessages'])->name('chat.messages');
    Route::post('/{conversationId}/close', [ChatController::class, 'close'])->name('chat.close');
    Route::post('/{conversationId}/rating', [ChatController::class, 'submitRating'])->name('chat.rating');

    // Agent routes
    Route::middleware(['auth'])->group(function () {
        Route::get('/agent/conversations', [ChatController::class, 'agentConversations'])->name('chat.agent.conversations');
        Route::post('/{conversationId}/assign', [ChatController::class, 'assignAgent'])->name('chat.assign');
        Route::get('/agent/next', [ChatController::class, 'nextQueued'])->name('chat.agent.next');
    });
});

if (class_exists(Socialstream::class)) {
    require __DIR__.'/socialstream.php';
}

// Keep this last: it must never take precedence over account, chat, auth, or
// other storefront routes. It powers legacy CMS URLs such as /about.
Route::get('/{slug}', [CmsPageController::class, 'show'])->name('cms.pages.slug');
