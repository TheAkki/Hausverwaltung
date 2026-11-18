<div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
    <h3 class="font-semibold mb-4">{{ __('Kosten') }}</h3>
    @php
        $anyCostUsesKontosystem = $invoice->costs->contains(fn ($cost) => $cost->managementGroup()?->kontosystemEnabled());
    @endphp
    <div class="overflow-x-auto mb-4">
    <table class="w-full text-sm text-left">
        <thead class="text-gray-600">
            <tr>
                <th class="py-2">{{ __('Zuordnung') }}</th>
                <th class="py-2">{{ __('Abrechnungsintervall') }}</th>
                <th class="py-2">{{ __('Periode') }}</th>
                <th class="py-2">{{ __('Kategorie') }}</th>
                <th class="py-2">{{ __('Betrag') }}</th>
                <th class="py-2">{{ __('Buchungsdatum') }}</th>
                <th class="py-2">{{ __('Status') }}</th>
                @if ($anyCostUsesKontosystem)
                    <th class="py-2">{{ __('Konto') }}</th>
                @endif
                <th class="py-2"></th>
                <th class="py-2"></th>
            </tr>
        </thead>
        <tbody class="divide-y">
            @foreach ($invoice->costs as $cost)
                @php
                    $costManagementGroup = $cost->managementGroup();
                    $costableIsLease = $cost->costable instanceof \App\Models\Lease;
                    $selectableCategories = $costableIsLease
                        ? $costManagementGroup?->costCategories()->wherePivot('allocation_key', 'individual')->orderBy('name')->get() ?? collect()
                        : $costCategories;
                    // Eine bereits bestehende, nicht-individuelle Kategorie (z. B. aus einem
                    // Import/Seed, der diese Prüfung nicht kennt) muss trotzdem als aktuell
                    // ausgewählt sichtbar bleiben, sonst würde ein Speichern eines anderen
                    // Feldes in dieser Zeile die Kategorie stillschweigend auf die erste
                    // Option der gefilterten Liste umstellen.
                    if ($costableIsLease && ! $selectableCategories->contains('id', $cost->cost_category_id)) {
                        $selectableCategories = $selectableCategories->push($cost->costCategory)->sortBy('name')->values();
                    }
                @endphp
                <tr>
                    <td class="py-2">{{ $cost->costableLabel() }}</td>
                    <td class="py-2">{{ $cost->billingInterval->start_date->format('d.m.Y') }}&ndash;{{ $cost->billingInterval->end_date->format('d.m.Y') }}</td>
                    <td class="py-2">
                        <form method="POST" action="{{ route('costs.update', $cost) }}" class="flex items-center gap-2">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="billing_interval_id" value="{{ $cost->billing_interval_id }}">
                            <input type="date" name="period_date" value="{{ optional($cost->period_date)->toDateString() }}" class="w-36 border-gray-300 rounded-md shadow-sm text-sm" onchange="this.form.submit()">
                    </td>
                    <td class="py-2">
                            <select name="cost_category_id" class="border-gray-300 rounded-md shadow-sm text-sm" onchange="this.form.submit()">
                                @foreach ($selectableCategories as $category)
                                    <option value="{{ $category->id }}" @selected($category->id === $cost->cost_category_id)>{{ $category->name }}</option>
                                @endforeach
                            </select>
                            @if ($costableIsLease)
                                <p class="text-xs text-gray-500 mt-1">{{ __('Nur Kategorien mit Abrechnungsart "Individuell".') }}</p>
                            @endif
                    </td>
                    <td class="py-2">
                            <input type="number" step="0.01" name="amount" value="{{ $cost->amount }}" class="w-24 border-gray-300 rounded-md shadow-sm text-sm" onblur="this.form.submit()">
                    </td>
                    <td class="py-2">
                            <input type="date" name="booking_date" value="{{ ($cost->booking_date ?? $cost->period_date)->toDateString() }}" class="w-36 border-gray-300 rounded-md shadow-sm text-sm {{ $cost->isOpen() ? 'text-gray-400' : '' }}" @if (! $cost->isOpen()) onchange="this.form.submit()" @endif>
                    </td>
                    <td class="py-2">
                        @if ($cost->isOpen())
                            <button type="submit" name="confirm_booking" value="1" class="text-red-600 hover:underline">{{ __('Offen – Buchen') }}</button>
                        @else
                            <span class="text-green-700">{{ __('Gebucht') }}</span>
                        @endif
                    </td>
                    @if ($anyCostUsesKontosystem)
                        <td class="py-2">
                            @if ($costManagementGroup?->kontosystemEnabled())
                                <select name="account_id" class="border-gray-300 rounded-md shadow-sm text-sm" @if (! $cost->isOpen()) onchange="this.form.submit()" @endif>
                                    <option value="">{{ __('– kein Konto –') }}</option>
                                    @foreach ($costManagementGroup->accounts as $account)
                                        <option value="{{ $account->id }}" @selected($cost->accounts->contains($account->id))>{{ $account->name }}</option>
                                    @endforeach
                                </select>
                            @else
                                &ndash;
                            @endif
                            </form>
                        </td>
                    @else
                        </form>
                    @endif
                    <td class="py-2">
                        @php
                            $otherIntervals = ($billingIntervalsByCostable[$cost->costable_type.':'.$cost->costable_id] ?? [])
                                ->reject(fn ($bi) => $bi['id'] === $cost->billing_interval_id);
                        @endphp
                        @if ($otherIntervals->isNotEmpty())
                            <details>
                                <summary class="text-indigo-600 hover:underline cursor-pointer">{{ __('Teilen') }}</summary>
                                <form method="POST" action="{{ route('costs.split', $cost) }}" class="mt-2 flex flex-col gap-2 w-48">
                                    @csrf
                                    <label class="text-xs text-gray-500">
                                        {{ __('Betrag (abzuspalten)') }}
                                        <input type="number" step="0.01" min="{{ $cost->amount < 0 ? $cost->amount : '0.01' }}" max="{{ $cost->amount < 0 ? '-0.01' : $cost->amount }}" name="amount" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm" required>
                                    </label>
                                    <label class="text-xs text-gray-500">
                                        {{ __('Ziel-Abrechnungsintervall') }}
                                        <select name="billing_interval_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm" required>
                                            @foreach ($otherIntervals as $interval)
                                                <option value="{{ $interval['id'] }}">{{ $interval['label'] }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <button type="submit" class="self-start text-indigo-600 hover:underline">{{ __('Aufteilen') }}</button>
                                </form>
                            </details>
                        @endif
                    </td>
                    <td class="py-2">
                        <form method="POST" action="{{ route('costs.destroy', $cost) }}" onsubmit="return confirm('{{ __('Kostenzeile wirklich löschen?') }}')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-red-600 hover:underline">{{ __('Entfernen') }}</button>
                        </form>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    </div>

    @if ($invoice->costable && in_array($invoice->periodicity, ['weekly', 'biweekly', 'monthly', 'quarterly', 'yearly'], true))
        <div class="mb-6 p-4 bg-gray-50 rounded-lg">
            <h4 class="font-medium text-sm mb-2">{{ __('Serie vervollständigen') }}</h4>
            <p class="text-xs text-gray-500 mb-2">{{ __('Erzeugt die fehlenden Kostenzeilen für alle Perioden seit Beginn dieser wiederkehrenden Rechnung bis zum gewählten Datum (bereits vorhandene Perioden werden übersprungen).') }}</p>
            <form method="POST" action="{{ route('invoices.complete-series', $invoice) }}" class="grid grid-cols-3 gap-3 items-end">
                @csrf
                <div>
                    <x-input-label for="complete_series_cost_category_id" :value="__('Kategorie')" />
                    <select id="complete_series_cost_category_id" name="cost_category_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                        @foreach ($costCategories as $category)
                            <option value="{{ $category->id }}" @selected($invoice->costs->first() && $category->id === $invoice->costs->first()->cost_category_id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="complete_series_until" :value="__('Bis (Standard: heute)')" />
                    <x-text-input id="complete_series_until" name="until" type="date" class="mt-1 block w-full" value="{{ now()->toDateString() }}" />
                </div>
                <div>
                    <x-primary-button>{{ __('Serie vervollständigen') }}</x-primary-button>
                </div>
            </form>
        </div>
    @endif

    <h4 class="font-medium text-sm mb-2">{{ __('Kostenzeile hinzufügen') }}</h4>
    <form method="POST" action="{{ route('costs.store') }}" class="grid grid-cols-2 gap-3 items-end">
        @csrf
        <input type="hidden" name="invoice_id" value="{{ $invoice->id }}">

        @php
            $invoiceCostableKey = $invoice->costable
                ? $invoice->costable->getMorphClass().':'.$invoice->costable->getKey()
                : null;
        @endphp

        <div>
            <x-input-label for="new_costable" :value="__('Zuordnung')" />
            <select id="new_costable" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                @foreach ($costables as $key => $label)
                    <option value="{{ $key }}" @selected($key === $invoiceCostableKey)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <x-input-label for="new_billing_interval_id" :value="__('Abrechnungsintervall')" />
            <select id="new_billing_interval_id" name="billing_interval_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required></select>
        </div>

        <div>
            <x-input-label for="new_cost_category_id" :value="__('Kategorie')" />
            <select id="new_cost_category_id" name="cost_category_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required></select>
            <p id="new_cost_category_id_hint" class="text-xs text-gray-500 mt-1 hidden">{{ __('Einem Mietvertrag direkt zugewiesene Kosten zählen in der Betriebskostenabrechnung nur, wenn die Kategorie für diese Verwaltungsgruppe die Abrechnungsart "Individuell" hat – daher stehen hier nur diese Kategorien zur Auswahl.') }}</p>
        </div>

        <div>
            <x-input-label for="new_amount" :value="__('Betrag')" />
            <x-text-input id="new_amount" name="amount" type="number" step="0.01" class="mt-1 block w-full" value="{{ number_format($invoice->effectiveAmount(), 2, '.', '') }}" required />
        </div>

        <div>
            <x-input-label for="new_period_date" :value="__('Periode (Monat/Jahr der Abbuchung)')" />
            <x-text-input id="new_period_date" name="period_date" type="date" class="mt-1 block w-full" value="{{ optional($invoice->booking_date ?? $invoice->invoice_date)->toDateString() }}" required />
        </div>

        <div>
            <x-input-label for="new_booking_date" :value="__('Buchungsdatum (optional, leer = offen)')" />
            <x-text-input id="new_booking_date" name="booking_date" type="date" class="mt-1 block w-full" value="{{ $invoice->periodicity === 'once' ? optional($invoice->booking_date)->toDateString() : '' }}" />
        </div>

        <div id="new_account_field" class="hidden">
            <x-input-label for="new_account_id" :value="__('Konto')" />
            <select id="new_account_id" name="account_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                <option value="">{{ __('– kein Konto –') }}</option>
            </select>
        </div>

        <input type="hidden" name="costable_type" id="new_costable_type">
        <input type="hidden" name="costable_id" id="new_costable_id">

        <div class="col-span-2">
            <x-input-error :messages="$errors->get('amount')" class="mt-2" />
            <x-primary-button>{{ __('Hinzufügen') }}</x-primary-button>
        </div>
    </form>

    <script>
        (function () {
            const billingIntervalsByCostable = @json($billingIntervalsByCostable);
            const kontenByCostable = @json($kontenByCostable);
            const costCategoriesByCostable = @json($costCategoriesByCostable);
            const costableSelect = document.getElementById('new_costable');
            const billingIntervalSelect = document.getElementById('new_billing_interval_id');
            const costCategorySelect = document.getElementById('new_cost_category_id');
            const costCategoryHint = document.getElementById('new_cost_category_id_hint');
            const costableTypeInput = document.getElementById('new_costable_type');
            const costableIdInput = document.getElementById('new_costable_id');
            const accountField = document.getElementById('new_account_field');
            const accountSelect = document.getElementById('new_account_id');

            function refresh() {
                const [type, id] = costableSelect.value.split(':');
                costableTypeInput.value = type;
                costableIdInput.value = id;
                const options = billingIntervalsByCostable[costableSelect.value] ?? [];
                billingIntervalSelect.innerHTML = options.map(o => `<option value="${o.id}">${o.label}</option>`).join('');

                const categoryOptions = costCategoriesByCostable[costableSelect.value] ?? [];
                costCategorySelect.innerHTML = categoryOptions.map(o => `<option value="${o.id}">${o.label}</option>`).join('');
                costCategoryHint.classList.toggle('hidden', type !== 'lease');

                const konten = kontenByCostable[costableSelect.value] ?? [];
                accountField.classList.toggle('hidden', konten.length === 0);
                accountSelect.innerHTML = '<option value="">{{ __('– kein Konto –') }}</option>'
                    + konten.map(o => `<option value="${o.id}">${o.label}</option>`).join('');
            }

            costableSelect.addEventListener('change', refresh);
            refresh();
        })();
    </script>
</div>
