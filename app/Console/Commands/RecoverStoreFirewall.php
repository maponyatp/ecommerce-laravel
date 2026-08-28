<?php

namespace App\Console\Commands;

use App\Models\FirewallRule;
use App\Models\SecuritySetting;
use App\Services\StaffSecurityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecoverStoreFirewall extends Command
{
    protected $signature = 'security:firewall-recover {--ip= : Revoke one exact IP rule} {--monitor : Switch to monitor mode} {--apply : Apply the recovery; otherwise preview only}';
    protected $description = 'CLI recovery for application-firewall lockouts without deleting audit history';

    public function handle(): int
    {
        if (!$this->option('ip') && !$this->option('monitor')) { $this->error('Choose --ip or --monitor.'); return self::FAILURE; }
        if ($this->option('ip') && !filter_var($this->option('ip'),FILTER_VALIDATE_IP)) { $this->error('Invalid IP address.'); return self::FAILURE; }
        if (!$this->option('apply')) { $this->info('Preview only. Add --apply to revoke the selected address and/or enter monitor mode.'); return self::SUCCESS; }
        DB::transaction(function () {
            if ($this->option('monitor')) {
                $setting = SecuritySetting::firstOrCreate(['id'=>1]);
                $setting->update(['firewall_mode'=>'monitor','version'=>$setting->version+1]);
                app(StaffSecurityService::class)->record('firewall.cli_recovery',null,$setting,['mode'=>'monitor']);
            }
            if ($this->option('ip')) {
                $rule = FirewallRule::where('ip_address',inet_ntop(inet_pton($this->option('ip'))))->lockForUpdate()->first();
                if ($rule) {
                    $rule->update(['revoked_at'=>now(),'version'=>$rule->version+1]);
                    app(StaffSecurityService::class)->record('firewall.cli_recovery',null,$rule,['rule_id'=>$rule->id]);
                }
            }
        });
        $this->info('Recovery applied and recorded.');
        return self::SUCCESS;
    }
}
