<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\StaffSecurityService;
use Closure;
use Illuminate\Http\Request;

class StaffRequestAudit
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $actor = $request->user();
        if ($actor instanceof User && $actor->hasAnyRole(['admin', 'super_admin'])
            && ($request->is('admin', 'admin/*', 'operations/*', 'livewire*') || !$request->isMethodSafe())) {
            $actions = [];
            if ($request->is('livewire*')) {
                foreach (array_slice((array) $request->input('components', []), 0, 10) as $component) {
                    foreach (array_slice((array) ($component['calls'] ?? []), 0, 10) as $call) {
                        if (is_string($call['method'] ?? null) && preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,80}$/D', $call['method'])) { $actions[] = $call['method']; }
                    }
                }
            }
            app(StaffSecurityService::class)->bestEffort('staff.request', $actor, null, ['status_code' => $response->getStatusCode(), 'actions' => $actions],
                $response->getStatusCode() >= 400 ? 'denied_or_failed' : 'completed');
        }
        return $response;
    }
}
