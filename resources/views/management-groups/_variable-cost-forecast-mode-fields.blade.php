{{--
    Shared by create.blade.php/edit.blade.php. $selected = the currently chosen mode
    (ManagementGroup::VARIABLE_FORECAST_*).
--}}
<div>
    <x-input-label :value="__('Prognose für variable Rechnungen')" />
    <p class="text-sm text-gray-500 mt-1">
        {{ __('Gilt nur für Rechnungen mit deaktivierter Betragsüberprüfung oder deren Verteilung zwischen mehreren Kategorien sich über die Buchungen verändert hat (z. B. ein Kredit mit wechselndem Zins-/Tilgungsanteil) – bestimmt, welchen Betrag noch nicht gebuchte, zukünftige Kostenzeilen einer solchen Rechnung als Prognose zeigen.') }}
    </p>
    <div class="mt-2 space-y-2">
        <label class="flex items-start gap-2">
            <input type="radio" name="variable_cost_forecast_mode" value="{{ \App\Models\ManagementGroup::VARIABLE_FORECAST_NONE }}" class="mt-1" {{ $selected === \App\Models\ManagementGroup::VARIABLE_FORECAST_NONE ? 'checked' : '' }}>
            <span>
                <span class="block font-medium">{{ __('Keine Prognostizierung') }}</span>
                <span class="block text-sm text-gray-500">{{ __('Für noch nicht gebuchte Vorkommen wird keine Kostenzeile angelegt.') }}</span>
            </span>
        </label>
        <label class="flex items-start gap-2">
            <input type="radio" name="variable_cost_forecast_mode" value="{{ \App\Models\ManagementGroup::VARIABLE_FORECAST_LAST_BOOKING }}" class="mt-1" {{ $selected === \App\Models\ManagementGroup::VARIABLE_FORECAST_LAST_BOOKING ? 'checked' : '' }}>
            <span>
                <span class="block font-medium">{{ __('Prognostizierung mit der letzten Buchung') }}</span>
                <span class="block text-sm text-gray-500">{{ __('Übernimmt den zuletzt tatsächlich gebuchten Betrag als Schätzung für die kommenden Vorkommen.') }}</span>
            </span>
        </label>
        <label class="flex items-start gap-2">
            <input type="radio" name="variable_cost_forecast_mode" value="{{ \App\Models\ManagementGroup::VARIABLE_FORECAST_REGRESSION }}" class="mt-1" {{ $selected === \App\Models\ManagementGroup::VARIABLE_FORECAST_REGRESSION ? 'checked' : '' }}>
            <span>
                <span class="block font-medium">{{ __('Buchung über Regressionsberechnung (Ausgleichsfunktion)') }}</span>
                <span class="block text-sm text-gray-500">{{ __('Setzt den Trend der bisherigen Buchungen linear fort, statt den letzten Betrag einzufrieren.') }}</span>
            </span>
        </label>
    </div>
    <x-input-error :messages="$errors->get('variable_cost_forecast_mode')" class="mt-2" />
</div>
