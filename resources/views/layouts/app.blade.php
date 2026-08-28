<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
@php
    $siteSettings = app(\App\Settings\GeneralSettings::class);
    $fontFamilies = [
        'modern' => "Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
        'editorial' => "Georgia, 'Times New Roman', serif",
        'classic' => "Arial, Helvetica, sans-serif",
    ];
    $storeFont = $fontFamilies[$siteSettings->store_font_style] ?? $fontFamilies['modern'];
    $shareImage = $siteSettings->seo_share_image_path
        ? asset('storage/' . $siteSettings->seo_share_image_path)
        : ($siteSettings->site_logo_path ? asset('storage/' . $siteSettings->site_logo_path) : asset('images/logo.png'));
@endphp
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', $siteSettings->site_name)</title>
    <meta name="description" content="@yield('meta_description', $siteSettings->seo_description)">
    <meta name="keywords" content="@yield('meta_keywords', $siteSettings->seo_keywords)">

    <!-- Open Graph / Social Media Meta Tags -->
    <meta property="og:title" content="@yield('og_title', $siteSettings->site_name)">
    <meta property="og:description" content="@yield('og_description', $siteSettings->seo_description)">
    <meta property="og:image" content="@yield('og_image', $shareImage)">
    <meta property="og:url" content="{{ url()->current() }}">
    @if(request()->routeIs('products.show'))
        <link rel="canonical" href="{{ url()->current() }}">
        <meta property="og:type" content="product">
        <meta name="twitter:card" content="summary_large_image">
    @endif
    @if($siteSettings->favicon_path)
        <link rel="icon" href="{{ asset('storage/' . $siteSettings->favicon_path) }}">
    @endif

    <!-- Styles -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    @stack('styles')
    <style>
        :root { --store-primary: {{ $siteSettings->store_primary_color }}; --store-background: {{ $siteSettings->store_background_color }}; }
        .store-primary-bg { background-color: var(--store-primary) !important; }
        .store-primary-text { color: var(--store-primary) !important; }
        .store-primary-border { border-color: var(--store-primary) !important; }
        .store-page-background { background-color: var(--store-background) !important; }
    </style>
    @include('themes.styles')
</head>
<body class="min-h-screen flex flex-col store-page-background" style="font-family: {{ $storeFont }}">
    @isset($themePreview)
        <aside role="status" style="background:#172554;color:#fff;padding:16px;text-align:center;font:14px/1.5 system-ui;">
            Private homepage preview: <strong>{{ $themePreview->name }}</strong>. Nothing is published. Forms are disabled; links open the live store.
            <a href="{{ \App\Filament\Admin\Pages\ThemeLibrary::getUrl() }}" style="color:#fff;text-decoration:underline;">Back to theme library</a>
        </aside>
    @endisset
    <x-home-navbar />

    <!-- Enhanced Flash Messages -->
    <div id="flash-messages" class="fixed inset-x-4 top-4 z-50 space-y-2 sm:left-auto sm:right-4 sm:w-full sm:max-w-md">
        @if (session('success'))
            <div class="alert alert-success shadow-lg max-w-md animate-slide-in-right" role="alert">
                <div class="flex items-center">
                    <svg class="w-5 h-5 mr-2 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                    </svg>
                    <span class="font-medium">{{ session('success') }}</span>
                    <button type="button" class="ml-auto text-green-600 hover:text-green-800" onclick="this.parentElement.parentElement.remove()">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                        </svg>
                    </button>
                </div>
            </div>
        @endif

        @if (session('error'))
            <div class="alert alert-error shadow-lg max-w-md animate-slide-in-right" role="alert">
                <div class="flex items-center">
                    <svg class="w-5 h-5 mr-2 text-red-600" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                    </svg>
                    <span class="font-medium">{{ session('error') }}</span>
                    <button type="button" class="ml-auto text-red-600 hover:text-red-800" onclick="this.parentElement.parentElement.remove()">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                        </svg>
                    </button>
                </div>
            </div>
        @endif

        @if (session('warning'))
            <div class="alert alert-warning shadow-lg max-w-md animate-slide-in-right" role="alert">
                <div class="flex items-center">
                    <svg class="w-5 h-5 mr-2 text-yellow-600" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                    </svg>
                    <span class="font-medium">{{ session('warning') }}</span>
                    <button type="button" class="ml-auto text-yellow-600 hover:text-yellow-800" onclick="this.parentElement.parentElement.remove()">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                        </svg>
                    </button>
                </div>
            </div>
        @endif
    </div>

    <main class="grow">
        @yield('content')
    </main>

    <!-- Chat Widget -->
    @unless(isset($themePreview))
        @livewire('chat-widget')
    @endunless

    @php($hasCustomFooterMenu = \App\Models\Menu::query()->where('slug', 'footer')->whereHas('items')->exists())
    <footer class="store-primary-bg text-white py-16 mt-16 border-t border-zinc-800 tracking-wider text-[11px]">
        <div class="container mx-auto px-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
                <div>
                    @if(filled($siteSettings->site_logo_path))
                        <img src="{{ asset('storage/' . $siteSettings->site_logo_path) }}" alt="{{ $siteSettings->site_name }}" class="mb-4 h-10 w-auto max-w-44 object-contain brightness-0 invert">
                    @else
                        <h3 class="text-xs font-black text-white uppercase tracking-widest mb-4">{{ $siteSettings->site_name }}</h3>
                    @endif
                    <p class="text-zinc-400 leading-relaxed">Your premier destination for fresh, hand-picked floral arrangements, custom bouquets, and gifts crafted for life's special moments.</p>
                </div>
                @if($hasCustomFooterMenu)
                    <div class="md:col-span-2">
                        <h3 class="text-xs font-black text-white uppercase tracking-widest mb-4">Explore</h3>
                        <x-filament-menu-builder::menu slug="footer" view="components.menus.footer-menu" />
                    </div>
                @else
                    <div>
                        <h3 class="text-xs font-black text-white uppercase tracking-widest mb-4">Quick Links</h3>
                        <ul class="space-y-2">
                            <li><a href="{{ route('home') }}" class="text-zinc-300 hover:text-white">Home</a></li>
                            <li><a href="{{ route('products.index') }}" class="text-zinc-300 hover:text-white">Products</a></li>
                            <li><a href="{{ route('contact') }}" class="text-zinc-300 hover:text-white">Contact</a></li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="text-xs font-black text-white uppercase tracking-widest mb-4">Customer Service</h3>
                        <ul class="space-y-2">
                            <li><a href="{{ route('contact') }}" class="text-zinc-300 hover:text-white">Frequently asked questions</a></li>
                            <li><a href="{{ route('contact') }}" class="text-zinc-300 hover:text-white">Delivery enquiries</a></li>
                            <li><a href="{{ route('contact') }}" class="text-zinc-300 hover:text-white">Returns support</a></li>
                        </ul>
                    </div>
                @endif
                <div>
                    <h3 class="text-xs font-black text-white uppercase tracking-widest mb-4">Connect With Us</h3>
                    <div class="flex space-x-4">
                        @if(filled(app(\App\Settings\GeneralSettings::class)->facebook_url))<a href="{{ app(\App\Settings\GeneralSettings::class)->facebook_url }}" aria-label="Facebook" class="text-zinc-300 hover:text-white text-sm"><i class="fab fa-facebook-f"></i></a>@endif
                        @if(filled(app(\App\Settings\GeneralSettings::class)->twitter_url))<a href="{{ app(\App\Settings\GeneralSettings::class)->twitter_url }}" aria-label="X / Twitter" class="text-zinc-300 hover:text-white text-sm"><i class="fab fa-twitter"></i></a>@endif
                        @if(filled(app(\App\Settings\GeneralSettings::class)->youtube_url))<a href="{{ app(\App\Settings\GeneralSettings::class)->youtube_url }}" aria-label="YouTube" class="text-zinc-300 hover:text-white text-sm"><i class="fab fa-youtube"></i></a>@endif
                    </div>
                </div>
            </div>
            <div class="border-t border-zinc-800 mt-12 pt-6 text-center text-zinc-500 text-[10px]">
                <p>{{ app(\App\Settings\GeneralSettings::class)->footer_copyright }}</p>
            </div>
        </div>
    </footer>

    @livewireScripts
    @stack('scripts')

    <!-- Enhanced JavaScript for UI/UX -->
    <script>
        // Auto-hide flash messages after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const flashMessages = document.querySelectorAll('#flash-messages .alert');
            flashMessages.forEach(function(message) {
                setTimeout(function() {
                    message.style.opacity = '0';
                    message.style.transform = 'translateX(100%)';
                    setTimeout(function() {
                        message.remove();
                    }, 300);
                }, 5000);
            });
        });

        // Enhanced loading states for forms
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(function(form) {
                form.addEventListener('submit', function() {
                    const submitButton = form.querySelector('button[type="submit"]');
                    if (submitButton) {
                        submitButton.disabled = true;
                        submitButton.innerHTML = '<span class="spinner mr-2"></span>Loading...';
                        submitButton.classList.add('loading');
                    }
                });
            });
        });

        // Enhanced tooltips
        function initTooltips() {
            const tooltipElements = document.querySelectorAll('[data-tooltip]');
            tooltipElements.forEach(function(element) {
                element.addEventListener('mouseenter', function() {
                    const tooltipText = this.getAttribute('data-tooltip');
                    const tooltip = document.createElement('div');
                    tooltip.className = 'tooltip';
                    tooltip.textContent = tooltipText;
                    tooltip.id = 'tooltip-' + Math.random().toString(36).substr(2, 9);

                    document.body.appendChild(tooltip);

                    const rect = this.getBoundingClientRect();
                    tooltip.style.left = rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2) + 'px';
                    tooltip.style.top = rect.top - tooltip.offsetHeight - 5 + 'px';

                    this.tooltipId = tooltip.id;
                });

                element.addEventListener('mouseleave', function() {
                    if (this.tooltipId) {
                        const tooltip = document.getElementById(this.tooltipId);
                        if (tooltip) {
                            tooltip.remove();
                        }
                    }
                });
            });
        }

        // Initialize tooltips
        document.addEventListener('DOMContentLoaded', initTooltips);

        // Enhanced smooth scrolling
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                const href = this.getAttribute('href');
                if (!href || href.length < 2) return;
                let id;
                try {
                    id = decodeURIComponent(href.slice(1));
                } catch {
                    return;
                }
                const target = document.getElementById(id);
                if (target) {
                    e.preventDefault();
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Enhanced image lazy loading with fade-in effect
        function initLazyLoading() {
            const images = document.querySelectorAll('img[data-src]');
            const imageObserver = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        img.src = img.dataset.src;
                        img.classList.remove('opacity-0');
                        img.classList.add('opacity-100');
                        observer.unobserve(img);
                    }
                });
            });

            images.forEach(img => {
                img.classList.add('opacity-0', 'transition-opacity', 'duration-300');
                imageObserver.observe(img);
            });
        }

        // Initialize lazy loading
        document.addEventListener('DOMContentLoaded', initLazyLoading);

        // Enhanced search with debouncing
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        // Add search suggestions functionality
        document.addEventListener('DOMContentLoaded', function() {
            const searchInputs = document.querySelectorAll('input[name="search"]');
            searchInputs.forEach(function(input) {
                const debouncedSearch = debounce(function(query) {
                    if (query.length > 2) {
                        // Add search suggestions logic here
                        console.log('Searching for:', query);
                    }
                }, 300);

                input.addEventListener('input', function() {
                    debouncedSearch(this.value);
                });
            });
        });
    </script>

    <!-- Custom CSS for animations -->
    <style>
        @keyframes slide-in-right {
            from {
                opacity: 0;
                transform: translateX(100%);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .animate-slide-in-right {
            animation: slide-in-right 0.3s ease-out;
        }

        @keyframes fade-in-up {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-fade-in-up {
            animation: fade-in-up 0.6s ease-out;
        }

        @keyframes pulse-scale {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
        }

        .animate-pulse-scale {
            animation: pulse-scale 2s infinite;
        }

        /* Enhanced focus styles for accessibility */
        *:focus {
            outline: 2px solid #06b6d4;
            outline-offset: 2px;
        }

        /* Enhanced button hover effects */
        .btn:hover {
            transform: translateY(-1px);
        }

        .btn:active {
            transform: translateY(0);
        }

        /* Enhanced card hover effects */
        .card:hover {
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }

        /* Enhanced loading spinner */
        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .spinner {
            animation: spin 1s linear infinite;
        }

        /* Enhanced skeleton loading */
        @keyframes skeleton-loading {
            0% {
                background-position: -200px 0;
            }
            100% {
                background-position: calc(200px + 100%) 0;
            }
        }

        .skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200px 100%;
            animation: skeleton-loading 1.5s infinite;
        }
    </style>
</body>
</html>
