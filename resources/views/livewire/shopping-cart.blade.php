<div class="shopping-cart">
    <h2 class="mb-4">Shopping Cart</h2>
    <p class="mb-4 text-sm">Prices and availability are checked against the current catalogue. Items are not reserved until checkout.</p>
    @if($cartIssues)
        <div class="alert alert-danger" role="alert">
            <p>Please resolve these items before checkout:</p>
            <ul>@foreach($cartIssues as $issue)<li>{{ $issue }}</li>@endforeach</ul>
            <button type="button" class="btn btn-outline-secondary mt-2" wire:click="clearCart">Clear saved cart</button>
        </div>
    @endif
    @if (session()->has('error'))
        <div class="alert alert-danger">
            {{ session('error') }}
        </div>
    @endif
    @if (session()->has('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if(count($items) > 0)
        <div class="card mb-4">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>Product</th>
                                <th>Price</th>
                                <th>Quantity</th>
                                <th>Total</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($items as $id => $item)
                                <tr wire:key="cart-line-{{ $id }}">
                                    <td>
                                        <div class="d-flex align-items-center">
                                            @if(isset($item['image']))
                                                <img src="{{ $item['image'] }}" alt="{{ $item['name'] }}" class="img-thumbnail mr-3" style="width: 50px; height: 50px; object-fit: cover;">
                                            @endif
                                            <div>
                                                <h6 class="mb-0">{{ $item['name'] }}</h6>
                                                @if($item['is_downloadable'])
                                                    <span class="badge bg-info text-white">Digital</span>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                    <td>{{ $item['issue'] ? 'Review item' : \App\Support\StoreMoney::format($item['price']) }}</td>
                                    <td>
                                        <div class="input-group" style="width: 120px;">
                                            <button type="button" aria-label="Decrease quantity for {{ $item['name'] }}" class="btn btn-outline-secondary btn-sm" wire:click="updateQuantity('{{ $id }}', {{ max(1, $item['quantity'] - 1) }})" wire:loading.attr="disabled">-</button>
                                            <input type="number" aria-label="Quantity for {{ $item['name'] }}" class="form-control form-control-sm text-center" value="{{ $item['quantity'] }}" wire:change="updateQuantity('{{ $id }}', $event.target.value)" min="1" max="9999" step="1" wire:loading.attr="disabled">
                                            <button type="button" aria-label="Increase quantity for {{ $item['name'] }}" class="btn btn-outline-secondary btn-sm" wire:click="updateQuantity('{{ $id }}', {{ $item['quantity'] + 1 }})" wire:loading.attr="disabled">+</button>
                                        </div>
                                    </td>
                                    <td>{{ $item['issue'] ? 'Review item' : \App\Support\StoreMoney::format($item['price'] * $item['quantity']) }}</td>
                                    <td>
                                        <button class="btn btn-sm btn-danger" wire:click="removeItem('{{ $id }}')">
                                            <i class="fa fa-trash"></i> Remove
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <button class="btn btn-outline-secondary" wire:click="clearCart">
                    <i class="fa fa-trash"></i> Clear Cart
                </button>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Order Summary</h5>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Subtotal:</span>
                            <strong>{{ $canCheckout ? \App\Support\StoreMoney::format($total) : 'Resolve unavailable items' }}</strong>
                        </div>
                        <div class="d-flex justify-content-between mb-3">
                            <span>Shipping:</span>
                            <span>Calculated at checkout</span>
                        </div>
                        <hr>
                        <div class="d-flex justify-content-between mb-3">
                            <span>Items total:</span>
                            <strong>{{ $canCheckout ? \App\Support\StoreMoney::format($total) : 'Resolve unavailable items' }}</strong>
                        </div>
                        <div class="d-grid">
                            <p class="mb-3 text-sm">Delivery, discounts and applicable taxes are calculated at checkout.</p>
                            @if($canResumeCheckout)
                                <p class="mb-3 text-sm">An existing checkout is linked to this unchanged cart. Review it before starting another payment.</p>
                                <a href="{{ route('checkout.initiate') }}" class="btn btn-primary">Review existing checkout</a>
                            @elseif($canCheckout)<a href="{{ route('checkout.initiate') }}" class="btn btn-primary">
                                Proceed to Checkout
                            </a>@else<button type="button" class="btn btn-primary" disabled>Resolve cart items to checkout</button>@endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @else
        <div class="card">
            <div class="card-body text-center py-5">
                <h4>Your cart is empty</h4>
                <p class="mb-4">Looks like you haven't added any products to your cart yet.</p>
                <a href="{{ route('products.index') }}" class="btn btn-primary">
                    Continue Shopping
                </a>
            </div>
        </div>
    @endif
</div>
