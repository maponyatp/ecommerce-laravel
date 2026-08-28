<x-filament-panels::page>
    <div class="space-y-6">
        <!-- OLAP Selectors Card -->
        <div class="p-6 bg-white rounded-xl border border-zinc-200 shadow-sm dark:bg-gray-900 dark:border-gray-850">
            <h3 class="text-lg font-bold text-black dark:text-white mb-6 uppercase tracking-wider">OLAP Business Intelligence Cube Explorer</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Cube Selector -->
                <div>
                    <label for="selectedCube" class="block text-xs font-bold text-zinc-500 uppercase tracking-wider mb-2">1. Select Analytics Cube</label>
                    <select wire:model.live="selectedCube" id="selectedCube" class="w-full rounded-none border-zinc-300 dark:border-zinc-700 dark:bg-gray-800 text-sm text-black dark:text-white focus:ring-black focus:border-black">
                        @foreach($cubes as $key => $name)
                            <option value="{{ $key }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Dimension Selector -->
                <div>
                    <label for="selectedDimension" class="block text-xs font-bold text-zinc-500 uppercase tracking-wider mb-2">2. Select Dimension</label>
                    <select wire:model.live="selectedDimension" id="selectedDimension" class="w-full rounded-none border-zinc-300 dark:border-zinc-700 dark:bg-gray-800 text-sm text-black dark:text-white focus:ring-black focus:border-black">
                        @foreach($dimensions as $key => $name)
                            <option value="{{ $key }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Measure Selector -->
                <div>
                    <label for="selectedMeasure" class="block text-xs font-bold text-zinc-500 uppercase tracking-wider mb-2">3. Select Measure</label>
                    <select wire:model.live="selectedMeasure" id="selectedMeasure" class="w-full rounded-none border-zinc-300 dark:border-zinc-700 dark:bg-gray-800 text-sm text-black dark:text-white focus:ring-black focus:border-black">
                        @foreach($measures as $key => $name)
                            <option value="{{ $key }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            <!-- Results Table -->
            <div class="lg:col-span-5 p-6 bg-white rounded-none border border-zinc-200 shadow-sm dark:bg-gray-900 dark:border-zinc-850">
                <h4 class="text-sm font-bold text-black dark:text-white uppercase tracking-wider mb-4">Cube Query Result Table</h4>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs text-zinc-500 dark:text-zinc-400">
                        <thead class="text-[10px] text-black uppercase bg-zinc-50 dark:bg-gray-800 dark:text-zinc-300 font-bold tracking-wider">
                            <tr>
                                <th class="px-4 py-3">Dimension Label</th>
                                <th class="px-4 py-3 text-right">Measure Value</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-gray-800">
                            @forelse($reportData as $row)
                                <tr class="hover:bg-zinc-50 dark:hover:bg-gray-800/50">
                                    <td class="px-4 py-3 font-semibold text-black dark:text-white">{{ $row->label }}</td>
                                    <td class="px-4 py-3 text-right font-mono font-bold text-black dark:text-white">
                                        @if(str_contains(strtolower($selectedMeasure), 'revenue') || str_contains(strtolower($selectedMeasure), 'value'))
                                            ${{ number_format($row->value, 2) }}
                                        @else
                                            {{ number_format($row->value, str_contains($selectedMeasure, 'rate') ? 2 : 0) }}
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="2" class="px-4 py-8 text-center text-zinc-400">No data found in this cube configuration.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Visual Bar Chart -->
            <div class="lg:col-span-7 p-6 bg-white rounded-none border border-zinc-200 shadow-sm dark:bg-gray-900 dark:border-zinc-850">
                <h4 class="text-sm font-bold text-black dark:text-white uppercase tracking-wider mb-4">Visual Data Visualization</h4>
                
                @php
                    $maxVal = collect($reportData)->max('value') ?: 1;
                @endphp

                <div class="space-y-4">
                    @forelse($reportData as $row)
                        <div>
                            <div class="flex justify-between text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                                <span>{{ $row->label }}</span>
                                <span class="font-bold text-black dark:text-white">
                                    @if(str_contains(strtolower($selectedMeasure), 'revenue') || str_contains(strtolower($selectedMeasure), 'value'))
                                        ${{ number_format($row->value, 2) }}
                                    @else
                                        {{ number_format($row->value, str_contains($selectedMeasure, 'rate') ? 2 : 0) }}
                                    @endif
                                </span>
                            </div>
                            <div class="w-full bg-zinc-100 dark:bg-gray-800 rounded-none h-3">
                                <div class="bg-black h-3 rounded-none" style="width: {{ max(2, min(100, ($row->value / $maxVal) * 100)) }}%"></div>
                            </div>
                        </div>
                    @empty
                        <div class="h-48 flex items-center justify-center text-zinc-400">
                            No visualization available.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
