<?php

namespace App\Observers;

use App\Services\StaffSecurityService;
use Illuminate\Database\Eloquent\Model;

class StaffAuditObserver
{
    public function created(Model $model): void { app(StaffSecurityService::class)->modelChanged($model, 'created'); }
    public function updated(Model $model): void { app(StaffSecurityService::class)->modelChanged($model, 'updated'); }
    public function deleted(Model $model): void { app(StaffSecurityService::class)->modelChanged($model, 'deleted'); }
}
