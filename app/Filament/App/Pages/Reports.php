<?php

namespace App\Filament\App\Pages;

use App\Models\AdvancedAnalytics;
use Filament\Pages\Page;

class Reports extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-chart-pie';

    protected string $view = 'filament.app.pages.reports';

    protected static ?int $navigationSort = 9;

    public string $selectedCube = 'sales';
    public string $selectedDimension = 'product_id';
    public string $selectedMeasure = 'revenue';
    
    public array $cubes = [];
    public array $dimensions = [];
    public array $measures = [];
    public array $reportData = [];

    public function mount(): void
    {
        $this->cubes = AdvancedAnalytics::getCubes();
        $this->updateSelectors();
        $this->runQuery();
    }

    public function updatedSelectedCube(): void
    {
        $this->updateSelectors();
        $this->runQuery();
    }

    public function updatedSelectedDimension(): void
    {
        $this->runQuery();
    }

    public function updatedSelectedMeasure(): void
    {
        $this->runQuery();
    }

    protected function updateSelectors(): void
    {
        $this->dimensions = AdvancedAnalytics::getDimensions($this->selectedCube);
        $this->measures = AdvancedAnalytics::getMeasures($this->selectedCube);

        // Ensure current selection is valid, otherwise set default
        if (!array_key_exists($this->selectedDimension, $this->dimensions)) {
            $this->selectedDimension = array_key_first($this->dimensions) ?: '';
        }
        if (!array_key_exists($this->selectedMeasure, $this->measures)) {
            $this->selectedMeasure = array_key_first($this->measures) ?: '';
        }
    }

    public function runQuery(): void
    {
        $this->reportData = AdvancedAnalytics::queryCube(
            $this->selectedCube,
            $this->selectedDimension,
            $this->selectedMeasure
        );
    }
}
