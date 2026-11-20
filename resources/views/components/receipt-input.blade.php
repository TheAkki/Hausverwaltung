{{-- Belegfeld eines Formulars (Forderung, Umbuchung): Datei hochladen, vorhandenen Beleg
     ansehen oder entfernen. $record ist null beim Anlegen, $route die Download-Route. --}}
@props(['record' => null, 'route', 'id' => 'file'])

<div {{ $attributes }}>
    <x-input-label :for="$id" :value="__('Beleg (optional)')" />
    @if ($record?->file_path)
        <p class="text-sm mt-1" data-receipt-current>
            <a href="{{ route($route, $record) }}" class="text-gray-600 hover:underline">{{ $record->file_original_name ?? __('Beleg') }}</a>
            <span class="text-gray-500">{{ __('– beim Hochladen einer neuen Datei wird sie ersetzt') }}</span>
        </p>
        <label class="inline-flex items-center gap-2 text-sm mt-1">
            <input type="checkbox" name="{{ \App\Services\RecordReceipt::FIELD_REMOVE }}" value="1" class="rounded border-gray-300">
            {{ __('Beleg entfernen') }}
        </label>
    @endif
    <input id="{{ $id }}" name="{{ \App\Services\RecordReceipt::FIELD_FILE }}" type="file" accept=".{{ implode(',.', \App\Support\FileStorage::ALLOWED_EXTENSIONS) }}" class="mt-1 block w-full text-sm">
    <x-input-error :messages="$errors->get(\App\Services\RecordReceipt::FIELD_FILE)" class="mt-2" />
</div>
