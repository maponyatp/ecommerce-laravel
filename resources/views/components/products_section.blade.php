<section class="bg-gray-50 py-8 antialiased dark:bg-gray-900 md:py-12">
    <div class="mx-auto max-w-(--breakpoint-xl) px-4 2xl:px-0">
      <div class="mb-4 items-end justify-between space-y-4 sm:flex sm:space-y-0 md:mb-8">
        <div>
          <h2 class="mt-3 text-xl font-semibold text-gray-900 dark:text-white sm:text-2xl">Our Products</h2>
        </div>
      </div>
      <div class="mb-4 grid gap-4 sm:grid-cols-2 md:mb-8 lg:grid-cols-3 xl:grid-cols-4">
        @foreach($products as $product)
            
            <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="h-56 w-full">
                  <a href="#">
                    <img class="mx-auto h-full dark:hidden" src="{{ $product->imageUrl }}" alt="{{ $product->name }}" />
                    <img class="mx-auto hidden h-full dark:block" src="{{ $product->imageUrl }}" alt="{{ $product->name }}" />
                  </a>
                </div>
                <div class="pt-6">
                  <a href="{{ route('products.show', ["product" => $product]) }}" class="text-lg font-semibold leading-tight text-gray-900 hover:underline dark:text-white">{{ $product->name }}</a>
        
            
                  <ul class="mt-2 flex items-center gap-4">
                    <li class="flex items-center gap-2">
                      <svg class="h-4 w-4 text-gray-500 dark:text-gray-400" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h6l2 4m-8-4v8m0-8V6a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1v9h2m8 0H9m4 0h2m4 0h2v-4m0 0h-5m3.5 5.5a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0Zm-10 0a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0Z" />
                      </svg>
                      <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Fast Delivery</p>
                    </li>
        
                    <li class="flex items-center gap-2">
                      <svg class="h-4 w-4 text-gray-500 dark:text-gray-400" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <path stroke="currentColor" stroke-linecap="round" stroke-width="2" d="M8 7V6c0-.6.4-1 1-1h11c.6 0 1 .4 1 1v7c0 .6-.4 1-1 1h-1M3 18v-7c0-.6.4-1 1-1h11c.6 0 1 .4 1 1v7c0 .6-.4 1-1 1H4a1 1 0 0 1-1-1Zm8-3.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Z" />
                      </svg>
                      <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Best Price</p>
                    </li>
                  </ul>
        
                  <div class="mt-4 flex items-center justify-between gap-4">
                    <p class="text-2xl font-extrabold leading-tight text-gray-900 dark:text-white">{{ $product->store_price_label }}</p>
        
                    <x-catalogue-buy-button :product="$product" />
                  </div>
                </div>
              </div>
        @endforeach
      </div>
    </div>
  </section>
