<?php

namespace Database\Seeders\DummyData;

use App\Models\Customer;
use App\Models\CustomerMetric;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductPerformance;
use App\Models\User;
use Illuminate\Database\Seeder;

class AdvancedAnalyticsSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Seed Product Performance
        $products = Product::all();
        $startDate = now()->subDays(14);

        foreach ($products as $product) {
            for ($i = 0; $i < 14; $i++) {
                $date = $startDate->copy()->addDays($i)->toDateString();
                $views = rand(50, 250);
                $addToCart = rand(10, 50);
                $purchases = rand(2, 12);
                $revenue = $purchases * $product->price;

                $performance = ProductPerformance::updateOrCreate(
                    ['product_id' => $product->id, 'date' => $date],
                    [
                        'views' => $views,
                        'add_to_cart' => $addToCart,
                        'purchases' => $purchases,
                        'revenue' => $revenue,
                        'conversion_rate' => 0,
                    ]
                );
                $performance->calculateConversionRate();
            }
        }

        // 2. Seed Customer Metrics
        $users = User::all();
        $segments = ['new', 'active', 'at_risk', 'churned', 'vip'];

        foreach ($users as $user) {
            // Avoid double seeding if user already has metrics
            if (CustomerMetric::where('user_id', $user->id)->exists()) {
                continue;
            }

            $totalOrders = rand(1, 10);
            $avgOrderVal = rand(35, 120);
            $ltv = $totalOrders * $avgOrderVal;
            $itemsPurchased = $totalOrders * rand(1, 3);
            
            CustomerMetric::create([
                'user_id' => $user->id,
                'lifetime_value' => $ltv,
                'average_order_value' => $avgOrderVal,
                'total_orders' => $totalOrders,
                'total_items_purchased' => $itemsPurchased,
                'first_purchase_at' => now()->subDays(rand(30, 90)),
                'last_purchase_at' => now()->subDays(rand(1, 25)),
                'customer_segment' => $user->email === 'admin@example.com' ? 'vip' : $segments[array_rand($segments)],
                'retention_score' => rand(30, 100),
            ]);
        }

        // 3. Seed Customers & Orders
        $firstNames = ['Sophia', 'Jackson', 'Olivia', 'Lucas', 'Emma', 'Aria', 'Mia', 'Oliver', 'Amelia', 'Liam'];
        $lastNames = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Miller', 'Davis', 'Wilson', 'Anderson', 'Thomas'];
        
        $customers = [];
        for ($i = 0; $i < 10; $i++) {
            $customers[] = Customer::create([
                'first_name' => $firstNames[$i],
                'last_name' => $lastNames[$i],
                'email' => strtolower($firstNames[$i]) . '.' . strtolower($lastNames[$i]) . '@example.com',
                'phone_number' => '555-01' . rand(10, 99),
                'address' => rand(100, 999) . ' Floral Way',
                'city' => 'Flower City',
                'state' => 'CA',
                'postal_code' => '90210',
            ]);
        }

        // Add 20 completed orders over the last 14 days
        if ($products->count() > 0) {
            for ($i = 0; $i < 20; $i++) {
                $customer = $customers[array_rand($customers)];
                $orderDate = now()->subDays(rand(0, 14))->toDateTimeString();
                
                // Create order
                $order = Order::create([
                    'customer_id' => $customer->id,
                    'customer_email' => $customer->email,
                    'order_date' => $orderDate,
                    'total_amount' => 0, // calculated below
                    'payment_status' => 'paid',
                    'shipping_status' => 'delivered',
                    'status' => 'completed',
                    'payment_method' => 'Credit Card',
                ]);

                $itemCount = rand(1, 3);
                $total = 0;
                
                for ($j = 0; $j < $itemCount; $j++) {
                    $product = $products->random();
                    $qty = rand(1, 2);
                    $price = $product->price;
                    $total += $price * $qty;

                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                        'quantity' => $qty,
                        'price' => $price,
                    ]);
                }

                $order->update(['total_amount' => $total]);
            }
        }
    }
}
