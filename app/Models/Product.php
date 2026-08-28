<?php

namespace App\Models;

use App\Interfaces\Orderable;
use App\Support\StoreMoney;
use App\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Product extends Model implements Orderable
{
    use HasFactory;
    use IsTenantModel;
    use SoftDeletes;

    protected $table = 'products';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'short_description',
        'long_description',
        'price',
        'category_id',
        'featured_image',
        'inventory_count',
        'low_stock_threshold',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'is_downloadable',
        'downloadable_file',
        'download_limit',
        'expiration_time',
        'pricing_type',
        'suggested_price',
        'minimum_price',
        'is_featured',
    ];

    protected $casts = [
        'has_variants' => 'boolean',
        'is_downloadable' => 'boolean',
        'price' => 'decimal:2',
        'suggested_price' => 'decimal:2',
        'minimum_price' => 'decimal:2',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function category()
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function collections()
    {
        return $this->belongsToMany(ProductCollection::class, 'collection_items');
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('order');
    }

    public function getImageUrlAttribute()
    {
        $path = $this->featured_image;
        if (! $path) {
            return asset('images/product-placeholder.svg');
        }
        if (Str::startsWith($path, ['https://', 'http://'])) {
            return $path;
        }
        if (Str::startsWith(ltrim($path, '/'), ['storage/', 'images/'])) {
            return asset(ltrim($path, '/'));
        }

        return Storage::disk('public')->url($path);
    }

    public function cartItems()
    {
        return $this->hasMany(CartItem::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function review()
    {
        return $this->hasMany(ProductReview::class);
    }

    public function rating()
    {
        return $this->hasMany(ProductRating::class);
    }

    public function downloadable()
    {
        return $this->hasOne(DownloadableProduct::class);
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function purchasableVariants()
    {
        return $this->variants()->where('active', true)->where('currency', StoreMoney::currency())->orderBy('position')->orderBy('id');
    }

    public function getStorePriceLabelAttribute(): string
    {
        if (! $this->has_variants) {
            return StoreMoney::format($this->price);
        }
        $price = $this->purchasableVariants()->min('price');

        return $price === null ? 'Options unavailable' : 'From '.StoreMoney::format($price);
    }

    public function options()
    {
        return $this->hasMany(ProductOption::class)->orderBy('position');
    }

    public function inventoryItems()
    {
        return $this->hasMany(InventoryItem::class);
    }

    public function seoSettings()
    {
        return $this->morphOne(SeoSetting::class, 'seoable');
    }

    public function analyticsEvents()
    {
        return $this->hasMany(AnalyticsEvent::class);
    }

    public function crossSells()
    {
        return $this->belongsToMany(
            Product::class,
            'product_cross_sells',
            'product_id',
            'cross_sell_product_id'
        )->withTimestamps()->orderBy('sort_order');
    }

    public function upsells()
    {
        return $this->belongsToMany(
            Product::class,
            'product_upsells',
            'product_id',
            'upsell_product_id'
        )->withTimestamps()->orderBy('sort_order');
    }

    public function relatedProducts()
    {
        return $this->belongsToMany(
            Product::class,
            'product_related',
            'product_id',
            'related_product_id'
        )->withTimestamps()->orderBy('sort_order');
    }

    public function taxClass()
    {
        return $this->belongsTo(TaxClass::class);
    }

    public function isDownloadable(): bool
    {
        return $this->is_downloadable && $this->downloadable()->exists();
    }

    protected static function booted(): void
    {
        static::creating(function ($product) {
            // Auto-generate slug if not provided
            if (empty($product->slug)) {
                $product->slug = Str::slug($product->name);
            }
        });

        static::updating(function ($product) {
            if ($product->has_variants && ($product->is_downloadable || ($product->pricing_type && $product->pricing_type !== 'fixed'))) {
                throw ValidationException::withMessages(['pricing_type' => 'Published variants require a fixed-price physical product. Create a separate product for a different selling model.']);
            }
            // Update slug if name changed and slug not manually set
            if ($product->isDirty('name') && ! $product->isDirty('slug')) {
                $product->slug = Str::slug($product->name);
            }
        });

        static::saved(function ($product) {
            if ($product->is_downloadable && $product->downloadable_file) {
                $product->downloadable()->updateOrCreate(
                    ['product_id' => $product->id],
                    [
                        'file_url' => $product->downloadable_file,
                        'download_limit' => $product->download_limit ?? PHP_INT_MAX,
                        'expiration_time' => $product->expiration_time,
                    ]
                );
            }
        });
    }

    public function scopeWithTag($query, Tag $tag)
    {
        return $query->whereHas('tags', function ($query) use ($tag) {
            $query->where('tags.id', $tag->id);
        });
    }

    public function scopeWithTagNames($query, array $tagNames)
    {
        return $query->whereHas('tags', function ($query) use ($tagNames) {
            $query->whereIn('name', $tagNames);
        });
    }

    public function scopeSearch($query, $keyword)
    {
        return $query->where(function ($q) use ($keyword) {
            $q->where('name', 'like', '%'.$keyword.'%')
                ->orWhere('description', 'like', '%'.$keyword.'%');
        });
    }

    public function scopeCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    public function scopePriceRange($query, $min, $max)
    {
        return $query->when($min, function ($q) use ($min) {
            $q->where('price', '>=', $min);
        })
            ->when($max, function ($q) use ($max) {
                $q->where('price', '<=', $max);
            });
    }

    public function scopePriceMin(Builder $query, $min): void
    {
        $query->whereRaw(static::storePriceSql().' >= CAST(? AS DECIMAL(12,2))', [StoreMoney::currency(), (float) $min]);
    }

    public function scopePriceMax(Builder $query, $max): void
    {
        $query->whereRaw(static::storePriceSql().' <= CAST(? AS DECIMAL(12,2))', [StoreMoney::currency(), (float) $max]);
    }

    public static function storePriceSql(): string
    {
        return '(CASE WHEN products.has_variants = 1 THEN (SELECT MIN(pv.price) FROM product_variants pv WHERE pv.product_id = products.id AND pv.active = 1 AND pv.currency = ?) ELSE products.price END)';
    }

    public function scopeOrderByStorePrice(Builder $query, bool $descending = false): void
    {
        $query->orderByRaw(static::storePriceSql().($descending ? ' DESC' : ' ASC'), [StoreMoney::currency()]);
    }

    public function isLowStock()
    {
        return $this->inventory_count <= $this->low_stock_threshold;
    }

    public function getPrice(): float
    {
        if ($this->isFree()) {
            return 0.00;
        }

        return $this->price;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isFree(): bool
    {
        return $this->pricing_type === 'free';
    }

    public function isDonationBased(): bool
    {
        return $this->pricing_type === 'donation';
    }

    public function hasVariants(): bool
    {
        return $this->variants()->exists();
    }

    public function getDefaultVariant()
    {
        return $this->variants()->orderBy('position')->first();
    }

    public function getTotalInventory(): int
    {
        if ($this->hasVariants()) {
            return $this->variants()->sum('inventory_quantity');
        }

        return $this->inventory_count;
    }

    public function getLowestPrice(): float
    {
        if ($this->hasVariants()) {
            return $this->variants()->min('price') ?? $this->price;
        }

        return $this->price;
    }

    public function getHighestPrice(): float
    {
        if ($this->hasVariants()) {
            return $this->variants()->max('price') ?? $this->price;
        }

        return $this->price;
    }

    public function getAverageRating(): float
    {
        return $this->rating()->avg('rating') ?? 0;
    }

    public function getTotalReviews(): int
    {
        return $this->review()->count();
    }
}
