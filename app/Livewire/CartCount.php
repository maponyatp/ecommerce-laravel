<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Session;
use Livewire\Attributes\On;
use Livewire\Component;

class CartCount extends Component
{
    #[On('cartUpdated')]
    public function refresh(): void {}

    public function render()
    {
        $count = 0;
        $cart = Session::get('cart', []);

        foreach (is_array($cart) ? $cart : [] as $item) {
            $quantity = is_array($item) ? ($item['quantity'] ?? null) : null;
            if ((is_int($quantity) || (is_string($quantity) && ctype_digit($quantity))) && $quantity >= 1 && $quantity <= 9999) {
                $count += (int) $quantity;
            }
        }

        return view('livewire.cart-count', [
            'count' => $count,
        ]);
    }
}
