<?php

namespace Database\Seeders\DummyData;

use App\Models\ProductCategory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ProductCategorySeeder extends Seeder
{
    /**
     * Define the product categories to seed.
     * Each category can be easily customized or extended.
     */
    private array $categories = [
        [
            'name' => 'Roses',
            'slug' => 'roses',
            'description' => 'Fresh, romantic roses of all colors.',
        ],
        [
            'name' => 'Lilies',
            'slug' => 'lilies',
            'description' => 'Elegant white and colored lilies.',
        ],
        [
            'name' => 'Tulips',
            'slug' => 'tulips',
            'description' => 'Vibrant, cheerful spring tulips.',
        ],
        [
            'name' => 'Orchids',
            'slug' => 'orchids',
            'description' => 'Exotic potted moth orchids.',
        ],
        [
            'name' => 'Spring Blooms',
            'slug' => 'spring-blooms',
            'description' => 'Freshly handpicked spring bouquets.',
        ],
        [
            'name' => 'Sunflowers',
            'slug' => 'sunflowers',
            'description' => 'Bright, happy golden sunflowers.',
        ],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ($this->categories as $category) {
            ProductCategory::create($category);
        }
    }
}
