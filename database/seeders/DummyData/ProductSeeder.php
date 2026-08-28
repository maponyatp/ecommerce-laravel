<?php

namespace Database\Seeders\DummyData;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
         // Fetch categories by slug to avoid hardcoding IDs
         $roses = ProductCategory::where('slug', 'roses')->first();
         $lilies = ProductCategory::where('slug', 'lilies')->first();
         $tulips = ProductCategory::where('slug', 'tulips')->first();
         $orchids = ProductCategory::where('slug', 'orchids')->first();
         $springBlooms = ProductCategory::where('slug', 'spring-blooms')->first();
         $sunflowers = ProductCategory::where('slug', 'sunflowers')->first();

         // Products for Roses
         Product::create([
             'name' => 'Red Romance Bouquet', 
             'slug' => 'red-romance-bouquet',
             'category_id' => $roses->id, 
             'price' => 49.99, 
             'short_description' => 'A gorgeous luxury bouquet of deep red roses wrapped in premium light pink paper.',
             'featured_image' => 'images/flowers/bouquet_red_roses.png',
             'inventory_count' => 15,
             'is_featured' => true,
         ]);

         // Products for Lilies
         Product::create([
             'name' => 'Pure Elegance Lilies', 
             'slug' => 'pure-elegance-lilies',
             'category_id' => $lilies->id, 
             'price' => 54.99, 
             'short_description' => 'A fresh and elegant bouquet of white lilies with rich green foliage, arranged in a glass vase.',
             'featured_image' => 'images/flowers/bouquet_white_lilies.png',
             'inventory_count' => 10,
             'is_featured' => true,
         ]);

         // Products for Tulips
         Product::create([
             'name' => 'Spring Cheer Tulips', 
             'slug' => 'spring-cheer-tulips',
             'category_id' => $tulips->id, 
             'price' => 39.99, 
             'short_description' => 'A vibrant spring bouquet of mixed-color tulips in rustic craft paper wrapping.',
             'featured_image' => 'images/flowers/bouquet_mixed_tulips.png',
             'inventory_count' => 20,
             'is_featured' => true,
         ]);

         // Products for Orchids
         Product::create([
             'name' => 'Exotic Purple Orchid', 
             'slug' => 'exotic-purple-orchid',
             'category_id' => $orchids->id, 
             'price' => 45.00, 
             'short_description' => 'An elegant purple moth orchid plant in full bloom, potted in a minimalist ceramic container.',
             'featured_image' => 'images/flowers/orchid_plant.png',
             'inventory_count' => 8,
             'is_featured' => true,
         ]);

         // Products for Spring Blooms
         Product::create([
             'name' => 'Pastel Garden Spring Bloom', 
             'slug' => 'pastel-garden-spring-bloom',
             'category_id' => $springBlooms->id, 
             'price' => 59.99, 
             'short_description' => 'A delicate pastel-themed spring flower arrangement with ranunculus, peonies, and eucalyptus leaves.',
             'featured_image' => 'images/flowers/bouquet_spring_bloom.png',
             'inventory_count' => 12,
             'is_featured' => true,
         ]);

         // Products for Sunflowers
         Product::create([
             'name' => 'Vibrant Sunflowers Bouquet', 
             'slug' => 'vibrant-sunflowers-bouquet',
             'category_id' => $sunflowers->id, 
             'price' => 34.99, 
             'short_description' => 'A bright bouquet of fresh yellow sunflowers tied with a simple twine ribbon.',
             'featured_image' => 'images/flowers/bouquet_sunflowers.png',
             'inventory_count' => 25,
             'is_featured' => true,
         ]);
    }
}
