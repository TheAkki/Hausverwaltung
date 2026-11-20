@php
    $invoice = $invoice ?? null;
    $selectedCostable = $selectedCostable ?? null;
@endphp

<div>
    <x-input-label for="invoice_issuer_id" :value="__('Rechnungssteller')" />
    <select id="invoice_issuer_id" name="invoice_issuer_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
        @foreach ($invoiceIssuers as $issuer)
            <option value="{{ $issuer->id }}" @selected(old('invoice_issuer_id', $invoice?->invoice_issuer_id) == $issuer->id)>{{ $issuer->fullName() }}</option>
        @endforeach
    </select>
    <x-input-error :messages="$errors->get('invoice_issuer_id')" class="mt-2" />
    <a href="{{ route('invoice-issuers.create') }}" class="text-xs text-gray-500 hover:underline">{{ __('Neuer Rechnungssteller') }}</a>
</div>

<div>
    <x-input-label for="invoice_issuer_account_id" :value="__('Kundenaccount (optional)')" />
    <select id="invoice_issuer_account_id" name="invoice_issuer_account_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
        <option value="">{{ __('Kein Kundenaccount') }}</option>
    </select>
    <x-input-error :messages="$errors->get('invoice_issuer_account_id')" class="mt-2" />
</div>

<script>
    (function () {
        const accountsByIssuer = @json($accountsByIssuer);
        const issuerSelect = document.getElementById('invoice_issuer_id');
        const accountSelect = document.getElementById('invoice_issuer_account_id');
        const selectedAccountId = '{{ old('invoice_issuer_account_id', $invoice?->invoice_issuer_account_id) }}';

        function refreshAccounts() {
            const options = accountsByIssuer[issuerSelect.value] ?? [];
            accountSelect.innerHTML = '<option value="">{{ __('Kein Kundenaccount') }}</option>'
                + options.map(o => `<option value="${o.id}">${o.label}</option>`).join('');
            if (options.some(o => String(o.id) === selectedAccountId)) {
                accountSelect.value = selectedAccountId;
            }
        }

        issuerSelect.addEventListener('change', refreshAccounts);
        refreshAccounts();
    })();
</script>

<div class="grid grid-cols-2 gap-4">
    <div>
        <x-input-label for="invoice_number" :value="__('Rechnungsnummer')" />
        <x-text-input id="invoice_number" name="invoice_number" type="text" class="mt-1 block w-full" :value="old('invoice_number', $invoice?->invoice_number)" />
    </div>
    <div>
        <x-input-label for="invoice_date" :value="__('Rechnungsdatum')" />
        <x-text-input id="invoice_date" name="invoice_date" type="date" class="mt-1 block w-full" :value="old('invoice_date', optional($invoice?->invoice_date)->toDateString())" required />
        <x-input-error :messages="$errors->get('invoice_date')" class="mt-2" />
    </div>
</div>

<div>
    <x-input-label for="booking_date" :value="__('Buchungsdatum')" />
    <x-text-input id="booking_date" name="booking_date" type="date" class="mt-1 block w-full" :value="old('booking_date', optional($invoice?->booking_date)->toDateString())" />
</div>

<div>
    <x-input-label for="description" :value="__('Beschreibung')" />
    <textarea id="description" name="description" rows="2" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">{{ old('description', $invoice?->description) }}</textarea>
</div>

<div>
    <x-input-label for="file" :value="__('Datei (optional)')" />
    @if ($invoice?->file_path)
        <p class="text-sm mt-1">
            <a href="{{ route('files.invoice', $invoice) }}" class="text-gray-600 hover:underline">{{ __('Aktuelle Datei ansehen') }}</a>
            <span class="text-gray-500">{{ __('– beim Hochladen einer neuen Datei wird sie ersetzt') }}</span>
        </p>
    @endif
    <input id="file" name="file" type="file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" class="mt-1 block w-full text-sm">
    <x-input-error :messages="$errors->get('file')" class="mt-2" />
</div>

<div class="grid grid-cols-2 gap-4">
    <div>
        <x-input-label for="effective_amount" :value="__('Wirksamer Betrag (optional)')" />
        <x-text-input id="effective_amount" name="effective_amount" type="number" step="0.01" class="mt-1 block w-full" :value="old('effective_amount', $invoice?->effective_amount)" />
    </div>
    @unless ($invoice)
        <div id="amount-field">
            <x-input-label for="amount" :value="__('Rechnungsbetrag')" />
            <x-text-input id="amount" name="amount" type="number" step="0.01" class="mt-1 block w-full" :value="old('amount')" />
            <x-input-error :messages="$errors->get('amount')" class="mt-2" />
        </div>
    @endunless
</div>

<div class="flex items-center gap-2">
    <input id="disable_amount_cap" name="disable_amount_cap" type="checkbox" value="1" class="rounded border-gray-300 shadow-sm" {{ old('disable_amount_cap', $invoice?->disable_amount_cap) ? 'checked' : '' }}>
    <x-input-label for="disable_amount_cap" :value="__('Betragsdeckel deaktivieren (Kostenzeilen dürfen den Rechnungsbetrag pro Periode übersteigen, z. B. bei wechselndem Zins/Tilgung-Verhältnis)')" class="!mb-0" />
</div>

@if ($invoice && in_array($invoice->periodicity, ['weekly', 'biweekly', 'monthly', 'quarterly', 'yearly'], true))
    <div class="grid grid-cols-2 gap-4">
        <div>
            <x-input-label for="recurrence_start" :value="__('Wiederholung Start')" />
            <x-text-input id="recurrence_start" name="recurrence_start" type="date" class="mt-1 block w-full" :value="old('recurrence_start', optional($invoice->recurrence_start)->toDateString())" />
            <x-input-error :messages="$errors->get('recurrence_start')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="recurrence_end" :value="__('Wiederholung Ende (leer = kein festes Ende)')" />
            <x-text-input id="recurrence_end" name="recurrence_end" type="date" class="mt-1 block w-full" :value="old('recurrence_end', optional($invoice->recurrence_end)->toDateString())" />
            <x-input-error :messages="$errors->get('recurrence_end')" class="mt-2" />
            <p class="text-xs text-gray-500 mt-1">{{ __('Legt fest, bis wann diese wiederkehrende Rechnung über "Serie vervollständigen" fortgeschrieben werden kann.') }}</p>
        </div>
    </div>
@endif

@unless ($invoice)
    <div>
        <x-input-label for="periodicity" :value="__('Periodizität')" />
        <select id="periodicity" name="periodicity" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
            <option value="once" selected>{{ __('Einmalig') }}</option>
            <option value="weekly">{{ __('Wöchentlich') }}</option>
            <option value="biweekly">{{ __('2-wöchentlich') }}</option>
            <option value="monthly">{{ __('Monatlich') }}</option>
            <option value="quarterly">{{ __('Quartalsweise') }}</option>
            <option value="yearly">{{ __('Jährlich') }}</option>
            <option value="manual">{{ __('Händisch') }}</option>
        </select>
    </div>

    <div id="periodicity-recurrence" class="grid grid-cols-2 gap-4 hidden">
        <div>
            <x-input-label for="recurrence_start" :value="__('Wiederholung Start')" />
            <x-text-input id="recurrence_start" name="recurrence_start" type="date" class="mt-1 block w-full" :value="old('recurrence_start')" />
        </div>
        <div>
            <x-input-label for="recurrence_end" :value="__('Wiederholung Ende')" />
            <x-text-input id="recurrence_end" name="recurrence_end" type="date" class="mt-1 block w-full" :value="old('recurrence_end')" />
        </div>
    </div>

    <div id="periodicity-manual" class="hidden">
        <x-input-label :value="__('Termine und Beträge')" />
        <div id="occurrence-rows" class="space-y-2 mt-1"></div>
        <button type="button" id="add-occurrence" class="text-sm text-gray-600 hover:underline mt-2">{{ __('Termin hinzufügen') }}</button>
        <x-input-error :messages="$errors->get('occurrences')" class="mt-2" />
    </div>

    <template id="occurrence-row-template">
        <div class="flex items-center gap-2 occurrence-row">
            <input type="date" name="occurrences[__INDEX__][occurrence_date]" class="border-gray-300 rounded-md shadow-sm text-sm" required>
            <input type="number" step="0.01" name="occurrences[__INDEX__][amount]" placeholder="{{ __('Betrag') }}" class="border-gray-300 rounded-md shadow-sm text-sm" required>
            <button type="button" class="remove-occurrence text-red-600 text-sm">{{ __('Entfernen') }}</button>
        </div>
    </template>

    <hr class="my-4">

    <div>
        <x-input-label for="costable" :value="__('Verwaltungsgruppe / -einheit / Mieteinheit')" />
        <select id="costable" name="costable" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
            @foreach ($costables as $key => $label)
                <option value="{{ $key }}" @selected($key === $selectedCostable)>{{ $label }}</option>
            @endforeach
        </select>
        <x-input-error :messages="$errors->get('costable')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="billing_interval_id" :value="__('Abrechnungsintervall')" />
        <select id="billing_interval_id" name="billing_interval_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required></select>
        <x-input-error :messages="$errors->get('billing_interval_id')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="cost_category_id" :value="__('Kostenkategorie')" />
        <select id="cost_category_id" name="cost_category_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required></select>
        <p id="cost_category_id_hint" class="text-xs text-gray-500 mt-1 hidden">{{ __('Einem Mietvertrag direkt zugewiesene Kosten zählen in der Betriebskostenabrechnung nur, wenn die Kategorie für diese Verwaltungsgruppe die Abrechnungsart "Individuell" hat – daher stehen hier nur diese Kategorien zur Auswahl.') }}</p>
        <x-input-error :messages="$errors->get('cost_category_id')" class="mt-2" />
    </div>

    <script>
        (function () {
            const billingIntervalsByCostable = @json($billingIntervalsByCostable);
            const costCategoriesByCostable = @json($costCategoriesByCostable);
            const costableSelect = document.getElementById('costable');
            const billingIntervalSelect = document.getElementById('billing_interval_id');
            const costCategorySelect = document.getElementById('cost_category_id');
            const costCategoryHint = document.getElementById('cost_category_id_hint');

            function refreshBillingIntervals() {
                const options = billingIntervalsByCostable[costableSelect.value] ?? [];
                billingIntervalSelect.innerHTML = options.map(o => `<option value="${o.id}">${o.label}</option>`).join('');
            }

            function refreshCostCategories() {
                const options = costCategoriesByCostable[costableSelect.value] ?? [];
                costCategorySelect.innerHTML = options.map(o => `<option value="${o.id}">${o.label}</option>`).join('');
                costCategoryHint.classList.toggle('hidden', !costableSelect.value.startsWith('lease:'));
            }

            costableSelect.addEventListener('change', refreshBillingIntervals);
            costableSelect.addEventListener('change', refreshCostCategories);
            refreshBillingIntervals();
            refreshCostCategories();

            const periodicitySelect = document.getElementById('periodicity');
            const recurrenceFields = document.getElementById('periodicity-recurrence');
            const manualFields = document.getElementById('periodicity-manual');
            const amountField = document.getElementById('amount-field');

            function refreshPeriodicity() {
                const value = periodicitySelect.value;
                recurrenceFields.classList.toggle('hidden', !['weekly', 'biweekly', 'monthly', 'quarterly', 'yearly'].includes(value));
                manualFields.classList.toggle('hidden', value !== 'manual');
                if (amountField) {
                    amountField.classList.toggle('hidden', value === 'manual');
                }
            }

            periodicitySelect.addEventListener('change', refreshPeriodicity);
            refreshPeriodicity();

            let occurrenceIndex = 0;
            const template = document.getElementById('occurrence-row-template');
            const rowsContainer = document.getElementById('occurrence-rows');

            document.getElementById('add-occurrence').addEventListener('click', function () {
                const html = template.innerHTML.replaceAll('__INDEX__', occurrenceIndex++);
                const wrapper = document.createElement('div');
                wrapper.innerHTML = html;
                rowsContainer.appendChild(wrapper.firstElementChild);
            });

            rowsContainer.addEventListener('click', function (event) {
                if (event.target.classList.contains('remove-occurrence')) {
                    event.target.closest('.occurrence-row').remove();
                }
            });
        })();
    </script>
@endunless
