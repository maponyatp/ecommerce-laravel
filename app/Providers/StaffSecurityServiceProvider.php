<?php

namespace App\Providers;

use App\Observers\StaffAuditObserver;
use App\Services\StaffSecurityService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class StaffSecurityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach ([\Illuminate\Auth\Events\Login::class, \Illuminate\Auth\Events\Failed::class, \Illuminate\Auth\Events\Logout::class,
            \Illuminate\Auth\Events\Lockout::class, \Illuminate\Auth\Events\PasswordReset::class, \Illuminate\Auth\Events\Verified::class] as $event) {
            Event::listen($event, fn ($payload) => app(StaffSecurityService::class)->authEvent($payload));
        }
        foreach ([\App\Models\User::class, \App\Models\Order::class, \App\Models\Product::class, \App\Models\Page::class,
            \App\Models\StoreIntegration::class, \App\Models\Refund::class, \App\Models\CreditNote::class,
            \App\Models\Role::class, \App\Models\Permission::class] as $model) {
            $model::observe(StaffAuditObserver::class);
        }
    }
}
