<x-filament-panels::page>
    <div class="space-y-6">
        <!-- OLAP Selectors Card -->
        <div class="p-6 bg-white rounded-xl border border-gray-100 shadow-sm dark:bg-gray-900 dark:border-gray-800">
            <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-3">Store reports</h3>
            <p class="mb-6 text-sm text-gray-500">Paid item sales use {{ \App\Support\StoreMoney::currency() }} orders only, before order-level discounts, tax and shipping (up to 200 groups). This is not a net-revenue or accounting report. Engagement and legacy customer segments depend on their separate data collectors; unverified legacy monetary summaries are not offered.</p>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Cube Selector -->
                <div>
                    <label for="selectedCube" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">1. Select Analytics Cube</label>
                    <select wire:model.live="selectedCube" id="selectedCube" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-primary-500 focus:border-primary-500">
                        @foreach($cubes as $key => $name)
                            <option value="{{ $key }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Dimension Selector -->
                <div>
                    <label for="selectedDimension" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">2. Select Dimension</label>
                    <select wire:model.live="selectedDimension" id="selectedDimension" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-primary-500 focus:border-primary-500">
                        @foreach($dimensions as $key => $name)
                            <option value="{{ $key }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Measure Selector -->
                <div>
                    <label for="selectedMeasure" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">3. Select Measure</label>
                    <select wire:model.live="selectedMeasure" id="selectedMeasure" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-primary-500 focus:border-primary-500">
                        @foreach($measures as $key => $name)
                            <option value="{{ $key }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            <!-- Results Table -->
            <div class="lg:col-span-5 p-6 bg-white rounded-xl border border-gray-100 shadow-sm dark:bg-gray-900 dark:border-gray-800">
                <h4 class="text-base font-bold text-gray-900 dark:text-white mb-4">Cube Query Result Table</h4>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-gray-500 dark:text-gray-400">
                        <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-800 dark:text-gray-300">
                            <tr>
                                <th class="px-4 py-3">Dimension Label</th>
                                <th class="px-4 py-3 text-right">Measure Value</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse($reportData as $row)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                    <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">{{ $row->label }}</td>
                                    <td class="px-4 py-3 text-right font-mono font-semibold text-gray-900 dark:text-white">
                                        @if(str_contains(strtolower($selectedMeasure), 'revenue') || str_contains(strtolower($selectedMeasure), 'value'))
                                            {{ \App\Support\StoreMoney::format($row->value) }}
                                        @else
                                            {{ number_format($row->value, str_contains($selectedMeasure, 'rate') ? 2 : 0) }}
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="2" class="px-4 py-8 text-center text-gray-400">No data found in this cube configuration.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Visual Bar Chart -->
            <div class="lg:col-span-7 p-6 bg-white rounded-xl border border-gray-100 shadow-sm dark:bg-gray-900 dark:border-gray-800">
                <h4 class="text-base font-bold text-gray-900 dark:text-white mb-4">Visual Data Visualization</h4>
                
                @php
                    $maxVal = collect($reportData)->max('value') ?: 1;
                @endphp

                <div class="space-y-4">
                    @forelse($reportData as $row)
                        <div>
                            <div class="flex justify-between text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                <span>{{ $row->label }}</span>
                                <span>
                                    @if(str_contains(strtolower($selectedMeasure), 'revenue') || str_contains(strtolower($selectedMeasure), 'value'))
                                        {{ \App\Support\StoreMoney::format($row->value) }}
                                    @else
                                        {{ number_format($row->value, str_contains($selectedMeasure, 'rate') ? 2 : 0) }}
                                    @endif
                                </span>
                            </div>
                            <div class="w-full bg-gray-100 dark:bg-gray-800 rounded-full h-3">
                                <div class="bg-primary-600 h-3 rounded-full" style="width: {{ max(2, min(100, ($row->value / $maxVal) * 100)) }}%"></div>
                            </div>
                        </div>
                    @empty
                        <div class="h-48 flex items-center justify-center text-gray-400">
                            No visualization available.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
