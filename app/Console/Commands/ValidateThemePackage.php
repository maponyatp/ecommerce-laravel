<?php

namespace App\Console\Commands;

use App\Services\ThemePackageService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class ValidateThemePackage extends Command
{
    protected $signature = 'theme:validate {zip : Local path to a flowershop-theme/v1 ZIP}';

    protected $description = 'Validate a designer theme package without importing, publishing or changing store data';

    public function handle(ThemePackageService $packages): int
    {
        try {
            $result = $packages->inspect($this->argument('zip'));
            $this->info('Valid '.$result['manifest']['schema'].' package: '.$result['manifest']['name'].' '.$result['manifest']['version']);
            $this->line(count($result['images']).' raster assets checked. No import or activation performed.');

            return self::SUCCESS;
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }
    }
}
