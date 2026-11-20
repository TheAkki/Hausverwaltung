{{-- Link auf den Beleg eines Datensatzes (geschützte Download-Route) oder "–". --}}
@props(['record', 'route'])

@if ($record->file_path)
    <a href="{{ route($route, $record) }}" class="text-gray-600 hover:underline" title="{{ $record->file_original_name }}" data-receipt-link>{{ __('Beleg') }}</a>
@else
    <span class="text-gray-400">&ndash;</span>
@endif
