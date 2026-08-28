<?php

namespace App\Livewire;

use App\Services\CartService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class ShoppingCart extends Component
{
    #[Locked]
    public array $items = [];

    public function render()
    {
        $snapshot = app(CartService::class)->snapshot();
        $this->items = $snapshot['items'];

        return view('livewire.shopping-cart', [
            'items' => $this->items, 'cartIssues' => $snapshot['errors'],
            'total' => $this->calculateTotal(), 'hasPhysicalProducts' => $this->hasPhysicalProducts(),
            'canCheckout' => count($this->items) > 0 && $snapshot['errors'] === [],
            'canResumeCheckout' => app(CartService::class)->canResumeCheckout(),
        ]);
    }

    #[On('addToCart')]
    public function addToCart(mixed $productId, mixed $name = null, mixed $price = null, mixed $quantity = 1, mixed $isDownloadable = null, mixed $weight = null): void
    {
        // Keep legacy event arguments compatible, but never trust their product metadata.
        $this->mutate(fn () => app(CartService::class)->add($productId, $quantity), 'Product added to cart successfully!');
    }

    public function updateQuantity(mixed $productId, mixed $quantity): void
    {
        $this->mutate(fn () => app(CartService::class)->update($productId, $quantity), 'Cart updated');
    }

    public function removeItem(mixed $productId): void
    {
        $this->mutate(fn () => app(CartService::class)->remove($productId), 'Item removed from cart');
    }

    public function clearCart(): void
    {
        $this->mutate(fn () => app(CartService::class)->clear(), 'Cart cleared');
    }

    private function mutate(callable $operation, string $message): void
    {
        $this->resetErrorBag();
        try {
            $operation();
            $this->dispatch('cartUpdated');
            session()->flash('success', $message);
        } catch (ValidationException $exception) {
            $this->addError('cart', $exception->validator->errors()->first());
        }
    }

    public function hasPhysicalProducts(): bool
    {
        return collect($this->items)->contains(fn ($item) => ! $item['is_downloadable']);
    }

    public function calculateTotal(): float
    {
        return round(collect($this->items)->sum(fn ($item) => (float) $item['price'] * $item['quantity']), 2);
    }
}
