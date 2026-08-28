<?php

namespace App\Http\Middleware;

use App\Models\FirewallRule;
use App\Services\StaffSecurityService;
use App\Services\StoreFirewallService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class StoreFirewall
{
    public function handle(Request $request, Closure $next)
    {
        if (!Schema::hasTable('firewall_rules')) { return $next($request); }
        $mode = app(StoreFirewallService::class)->mode();
        if ($mode === 'disabled') { return $next($request); }
        $ip = $request->ip();
        // Only the path is inspected. Never log signed URLs, payment fields, passwords or request bodies.
        $path = rawurldecode(rawurldecode($request->getPathInfo()));
        $probe = preg_match('~(?:^|/)(?:\.env(?:[./]|$)|\.git(?:/|$)|wp-admin(?:/|$)|wp-login\.php$|xmlrpc\.php$|phpmyadmin(?:/|$)|adminer\.php$)|(?:^|/)\.\.(?:/|$)|^/etc/passwd$~i', $path);
        $rule = filter_var($ip, FILTER_VALIDATE_IP) ? FirewallRule::where('ip_address', inet_ntop(inet_pton($ip)))->active()->first() : null;
        if ($probe || $rule) {
            if (Cache::add('security:firewall-event:'.hash('sha256', $ip.':'.($probe ? 'probe' : 'rule')), 1, 60)) {
                app(StaffSecurityService::class)->bestEffort('firewall.request', null, $rule,
                    ['mode' => $mode, 'reason_code' => $probe ? 'sensitive_path_probe' : 'active_ip_rule'], $mode === 'enforce' ? 'blocked' : 'observed');
            }
            if ($mode === 'enforce') { abort($rule ? 403 : 404); }
        }
        return $next($request);
    }
}
