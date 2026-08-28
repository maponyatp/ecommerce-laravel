@if($record)
<p class="text-sm">{{ \App\Support\OrderWorkspace::nextStep($record) }}</p>
@endif
