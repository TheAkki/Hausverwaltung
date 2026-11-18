{{-- Beleg (Datei) einer Rechnung: deutlich sichtbar mit "Öffnen" (neuer Tab, der Browser
     zeigt PDF/Bilder direkt an) und "Herunterladen" (mit Original-Dateiname). --}}
<div class="mt-4 flex flex-wrap items-center gap-3 rounded-md border px-4 py-3 {{ $invoice->file_path ? 'border-indigo-200 bg-indigo-50' : 'border-dashed border-gray-300 bg-gray-50' }}" data-receipt>
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-6 w-6 shrink-0 {{ $invoice->file_path ? 'text-indigo-600' : 'text-gray-400' }}" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
    </svg>

    @if ($invoice->file_path)
        <div class="min-w-0 flex-1">
            <div class="text-xs font-semibold uppercase tracking-wide text-indigo-700">{{ __('Beleg') }}</div>
            <div class="truncate text-sm text-gray-800" title="{{ $invoice->file_original_name }}">{{ $invoice->file_original_name ?? basename($invoice->file_path) }}</div>
        </div>
        <div class="flex gap-2">
            <a href="{{ Storage::url($invoice->file_path) }}" target="_blank" rel="noopener" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">{{ __('Öffnen') }}</a>
            <a href="{{ Storage::url($invoice->file_path) }}" download="{{ $invoice->file_original_name }}" class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">{{ __('Herunterladen') }}</a>
        </div>
    @else
        <div class="flex-1 text-sm text-gray-500">{{ __('Kein Beleg hinterlegt.') }}</div>
        <a href="{{ route('invoices.edit', $invoice) }}" class="text-sm text-indigo-700 hover:underline">{{ __('Beleg hochladen') }}</a>
    @endif
</div>
