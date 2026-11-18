@php
    $billingTemplate = $billingTemplate ?? null;
@endphp

<div>
    <x-input-label for="name" :value="__('Name')" />
    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $billingTemplate?->name)" required autofocus />
    <x-input-error :messages="$errors->get('name')" class="mt-2" />
</div>

<div>
    <x-input-label for="user_group_id" :value="__('Gruppe')" />
    <select id="user_group_id" name="user_group_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
        @foreach ($groups as $group)
            <option value="{{ $group->id }}" @selected(old('user_group_id', $billingTemplate?->user_group_id) == $group->id)>{{ $group->name }}</option>
        @endforeach
    </select>
    <x-input-error :messages="$errors->get('user_group_id')" class="mt-2" />
</div>

<div class="flex items-center gap-2">
    <input type="hidden" name="is_default" value="0">
    <input id="is_default" type="checkbox" name="is_default" value="1">
    <x-input-label for="is_default" :value="__('Als Standard-Template der ausgewählten Gruppe verwenden')" />
</div>

<div>
    <x-input-label for="header_image" :value="__('Kopf-Bild (optional)')" />
    @if ($billingTemplate?->headerImageUrl())
        <div class="flex items-center gap-3 mt-1 mb-2">
            <img src="{{ $billingTemplate->headerImageUrl() }}" alt="" class="h-12">
            <label class="text-xs text-gray-500 flex items-center gap-1">
                <input type="checkbox" name="remove_header_image" value="1">
                {{ __('Bild entfernen') }}
            </label>
        </div>
    @endif
    <input id="header_image" name="header_image" type="file" accept="image/*" class="mt-1 block w-full text-sm">
    <p class="text-xs text-gray-500 mt-1">{{ __('Im Kopf-/Fußzeilen-Blade als') }} <code>&lt;img src="@{{ $headerImage }}"&gt;</code> {{ __('einbindbar (funktioniert auch im PDF-Export).') }}</p>
    <x-input-error :messages="$errors->get('header_image')" class="mt-2" />
</div>

<div>
    <p class="text-xs text-gray-500 mb-2">{{ __('In den Template-Feldern rückt Tab ein und Umschalt+Tab aus. Zum Verlassen eines Feldes per Tastatur erst Esc, dann Tab drücken.') }}</p>
    <x-input-label for="header" :value="__('Kopfzeile (Blade-Syntax, wird auf jeder Seite wiederholt)')" />
    <textarea id="header" name="header" data-tab-indent rows="6" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm font-mono text-sm [tab-size:4]">{{ old('header', $billingTemplate?->header) }}</textarea>
    <x-input-error :messages="$errors->get('header')" class="mt-2" />
</div>

<div>
    <x-input-label for="content" :value="__('Inhalt (Blade-Syntax)')" />
    <textarea id="content" name="content" data-tab-indent rows="20" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm font-mono text-sm [tab-size:4]">{{ old('content', $billingTemplate?->content) }}</textarea>
    <p class="text-xs text-gray-500 mt-1">
        {{ __('Verfügbare Variablen: $managementGroup, $managementUnit, $billingInterval, $leases, $costsByCategory, $costsByMonth, $headerImage.') }}
        <a href="{{ route('billing-templates.help') }}" target="_blank" class="underline hover:text-gray-700">{{ __('Hilfe: Templates schreiben') }}</a>
    </p>
    <x-input-error :messages="$errors->get('content')" class="mt-2" />
</div>

<div>
    <x-input-label for="css_content" :value="__('CSS-Content (optional, Formatierung getrennt vom Inhalt)')" />
    <textarea id="css_content" name="css_content" data-tab-indent rows="12" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm font-mono text-sm [tab-size:4]">{{ old('css_content', $billingTemplate?->css_content) }}</textarea>
    <p class="text-xs text-gray-500 mt-1">
        {{ __('Wird beim Rendern in den Inhalt eingefügt – so lässt sich die Formatierung vom Inhalt getrennt pflegen und ein kopiertes Template leichter anpassen.') }}
    </p>
    <x-input-error :messages="$errors->get('css_content')" class="mt-2" />
</div>

<div>
    <x-input-label for="footer" :value="__('Fußzeile (Blade-Syntax, wird auf jeder Seite wiederholt)')" />
    <textarea id="footer" name="footer" data-tab-indent rows="6" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm font-mono text-sm [tab-size:4]">{{ old('footer', $billingTemplate?->footer) }}</textarea>
    <x-input-error :messages="$errors->get('footer')" class="mt-2" />
</div>

@if ($billingTemplate)
    <div>
        <button type="submit" formaction="{{ route('billing-templates.preview', $billingTemplate) }}" formtarget="_blank"
                class="px-4 py-2 bg-white border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
            {{ __('Vorschau mit Testdaten generieren') }}
        </button>
        <p class="text-xs text-gray-500 mt-1">
            {{ __('Rendert Kopfzeile, Inhalt und Fußzeile oben (auch ohne vorheriges Speichern) mit erfundenen Testdaten in einem neuen Tab.') }}
        </p>
    </div>

    <div>
        <button type="submit" formaction="{{ route('billing-templates.check', $billingTemplate) }}"
                class="px-4 py-2 bg-white border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
            {{ __('Template überprüfen') }}
        </button>
        <p class="text-xs text-gray-500 mt-1">
            {{ __('Prüft Kopfzeile, Inhalt und Fußzeile oben (auch ohne vorheriges Speichern) auf Blade-Syntaxfehler, unbekannte Variablen/Eigenschaften und fehlerhaftes HTML (z. B. einen falsch geschriebenen schließenden Tag).') }}
        </p>
        @if (session('status'))
            <p class="text-sm text-green-700 mt-1">{{ session('status') }}</p>
        @endif
        <x-input-error :messages="$errors->get('template_check')" class="mt-1" />
    </div>
@endif
