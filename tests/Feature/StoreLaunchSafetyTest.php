<?php

namespace Tests\Feature;

use App\Models\DeliverySlot;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ShippingMethod;
use App\Services\ShippingService;
use App\Services\StoreReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StoreLaunchSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function product(bool $digital = false): Product
    {
        $category = ProductCategory::create(['name' => 'Flowers', 'slug' => 'flowers']);

        return Product::create(['name' => 'Bouquet', 'slug' => 'bouquet', 'price' => 100,
            'inventory_count' => 1, 'category_id' => $category->id, 'is_downloadable' => $digital]);
    }

    private function method(array $overrides = []): ShippingMethod
    {
        return ShippingMethod::create($overrides + ['name' => 'Local delivery', 'base_rate' => 50,
            'weight_rate' => 0, 'max_weight' => 10, 'is_active' => true,
            'estimated_delivery_time' => 'Next working day']);
    }

    private function check(string $id): array
    {
        $checks = collect(app(StoreReadinessService::class)->report()['checks'])->keyBy('id');
        $this->assertTrue($checks->has($id), "Missing launch check: $id");

        return $checks[$id];
    }

    public function test_empty_and_deleted_catalogues_are_launch_blockers(): void
    {
        $this->assertFalse($this->check('catalogue')['passed']);
        $product = $this->product();
        $this->assertTrue($this->check('catalogue')['passed']);
        $product->delete();
        $this->assertFalse($this->check('catalogue')['passed']);
        Http::assertNothingSent();
    }

    public function test_digital_only_catalogue_does_not_require_physical_delivery(): void
    {
        $this->product(true);
        $this->assertTrue($this->check('delivery')['passed']);
    }

    public static function invalidMethods(): array
    {
        return [
            'inactive' => [['is_active' => false]],
            'negative base rate' => [['base_rate' => -1]],
            'negative weight rate' => [['weight_rate' => -1]],
            'missing maximum weight' => [['max_weight' => null]],
            'negative maximum weight' => [['max_weight' => -1]],
            'malformed postal codes' => [['postal_codes' => '0001']],
            'numeric postal code loses leading zeros' => [['postal_codes' => [1]]],
            'invalid postal code' => [['postal_codes' => ['0001', 'ABCD']]],
            'non-list postal codes' => [['postal_codes' => ['area' => '0001']]],
        ];
    }

    #[DataProvider('invalidMethods')]
    public function test_invalid_delivery_configuration_is_not_advertised_or_marked_ready(array $overrides): void
    {
        $this->product();
        $this->method($overrides);
        $service = app(ShippingService::class);
        $this->assertCount(0, $service->getAvailableShippingMethods());
        $this->assertCount(0, $service->getAvailableShippingMethods([['weight' => 0, 'quantity' => 1]], 'Street', '0001'));
        $this->assertFalse($this->check('delivery')['passed']);
    }

    public function test_valid_zero_rate_zero_weight_method_remains_available_and_postal_coverage_is_preserved(): void
    {
        $this->product();
        $this->method(['base_rate' => 0, 'weight_rate' => 0, 'max_weight' => 0, 'postal_codes' => ['0001']]);
        $service = app(ShippingService::class);
        $this->assertTrue($this->check('delivery')['passed']);
        $this->assertCount(1, $service->getAvailableShippingMethods([['weight' => 0, 'quantity' => 1]], 'Street', '0001'));
        $this->assertCount(0, $service->getAvailableShippingMethods([['weight' => 0, 'quantity' => 1]], 'Street', '2001'));
        $this->assertCount(0, $service->getAvailableShippingMethods([['weight' => 1, 'quantity' => 1]], 'Street', '0001'));
    }

    public function test_scheduled_delivery_requires_an_open_window_with_capacity(): void
    {
        $this->product();
        $method = $this->method(['requires_delivery_slot' => true]);
        $this->assertFalse($this->check('delivery')['passed']);
        $slot = DeliverySlot::create(['shipping_method_id' => $method->id, 'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHour(), 'booking_closes_at' => now()->addDay(),
            'capacity' => 1, 'is_active' => true]);
        $this->assertTrue($this->check('delivery')['passed']);
        $slot->update(['capacity' => 0]);
        $this->assertFalse($this->check('delivery')['passed']);
        $slot->update(['capacity' => 1, 'booking_closes_at' => now()->subMinute()]);
        $this->assertFalse($this->check('delivery')['passed']);
        $this->method();
        $this->assertTrue($this->check('delivery')['passed']);
        Http::assertNothingSent();
    }

    public function test_placeholder_address_verification_never_transmits_customer_data(): void
    {
        Http::fake(['*' => Http::response(['verified' => true])]);
        $this->assertNull(app(ShippingService::class)->verifyAddress('Private customer address'));
        Http::assertNothingSent();
    }
}
