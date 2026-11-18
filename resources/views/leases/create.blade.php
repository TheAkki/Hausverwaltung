<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Neuer Mietvertrag') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[691px] mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <form method="POST" action="{{ route('leases.store') }}" class="space-y-4">
                    @csrf

                    @if ($scope === 'rental_unit')
                        <input type="hidden" name="rental_unit_id" value="{{ $rentalUnit->id }}">
                        <div>
                            <x-input-label :value="__('Mieteinheit')" />
                            <p class="mt-1 text-sm text-gray-700 bg-gray-50 border border-gray-200 rounded-md px-3 py-2">
                                {{ $rentalUnit->managementUnit->street }} &mdash; {{ $rentalUnit->label() }}
                            </p>
                        </div>
                    @else
                        <div>
                            <x-input-label for="rental_unit_id" :value="__('Mieteinheit')" />
                            <select id="rental_unit_id" name="rental_unit_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                                <option value="" disabled selected>{{ __('Bitte wählen') }}</option>
                                @if ($scope === 'management_unit')
                                    @foreach ($rentalUnits as $unit)
                                        <option value="{{ $unit->id }}" @selected(old('rental_unit_id') == $unit->id)>{{ $unit->label() }}</option>
                                    @endforeach
                                @else
                                    @foreach ($rentalUnits->groupBy(fn ($u) => $u->managementUnit->managementGroup->description ?? __('Verwaltungsgruppe')) as $groupLabel => $units)
                                        <optgroup label="{{ $groupLabel }}">
                                            @foreach ($units as $unit)
                                                <option value="{{ $unit->id }}" @selected(old('rental_unit_id') == $unit->id)>
                                                    {{ $unit->managementUnit->street }} &mdash; {{ $unit->label() }}
                                                </option>
                                            @endforeach
                                        </optgroup>
                                    @endforeach
                                @endif
                            </select>
                            <x-input-error :messages="$errors->get('rental_unit_id')" class="mt-2" />
                        </div>
                    @endif

                    @php
                        // Konzept.md "### Detailbild Mieteinheit": eine nicht abrechnungsrelevante
                        // Mieteinheit (z. B. ein Stellplatz) kann ihren Mietvertrag entweder eigenen
                        // Mietern zuweisen, oder an einen bestehenden Mietvertrag einer
                        // abrechnungsrelevanten Mieteinheit derselben Verwaltungseinheit koppeln.
                        // Bei festem Kontext (Scope "rental_unit") steht die Kandidatenliste schon
                        // serverseitig fest; bei den anderen Scopes wird die Mieteinheit erst per
                        // <select> gewählt, daher entscheidet dort JavaScript anhand von
                        // $referenceableLeasesByRentalUnit, ob/welche Kandidaten angeboten werden.
                        $initiallyHasReference = $scope === 'rental_unit'
                            ? (isset($referenceableLeases) && $referenceableLeases->isNotEmpty())
                            : old('assignment_mode') === 'reference';
                    @endphp

                    <div id="assignment-mode-toggle" class="{{ $initiallyHasReference ? '' : 'hidden' }}">
                        <x-input-label :value="__('Mieterzuweisung')" />
                        <div class="mt-1 flex gap-4">
                            <label class="inline-flex items-center">
                                <input type="radio" name="assignment_mode" value="tenants" class="text-indigo-600" data-assignment-toggle @checked(old('assignment_mode', 'tenants') === 'tenants')>
                                <span class="ms-2 text-sm text-gray-700">{{ __('Mieter zuweisen') }}</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="radio" name="assignment_mode" value="reference" class="text-indigo-600" data-assignment-toggle @checked(old('assignment_mode') === 'reference')>
                                <span class="ms-2 text-sm text-gray-700">{{ __('Mietvertrag referenzieren') }}</span>
                            </label>
                        </div>
                    </div>

                    <div data-assignment-panel="reference" class="{{ old('assignment_mode') === 'reference' ? '' : 'hidden' }}">
                        <x-input-label for="coupled_to_lease_id" :value="__('Referenzierter Mietvertrag')" />
                        <select id="coupled_to_lease_id" name="coupled_to_lease_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                            <option value="" disabled selected>{{ __('Bitte wählen') }}</option>
                            @if (isset($referenceableLeases))
                                @foreach ($referenceableLeases as $referenceableLease)
                                    <option value="{{ $referenceableLease->id }}" @selected(old('coupled_to_lease_id') == $referenceableLease->id)>
                                        {{ $referenceableLease->rentalUnit->label() }} &mdash; {{ $referenceableLease->tenantNames() }}
                                    </option>
                                @endforeach
                            @endif
                        </select>
                        <p class="text-xs text-gray-500 mt-1">{{ __('Übernimmt die Mieter des referenzierten Mietvertrags; eine eigene Mieterzuweisung entfällt.') }}</p>
                        <x-input-error :messages="$errors->get('coupled_to_lease_id')" class="mt-2" />
                    </div>

                    <div data-assignment-panel="tenants" class="{{ old('assignment_mode', 'tenants') === 'reference' ? 'hidden' : '' }}">
                        <x-input-label for="tenant_ids" :value="__('Mieter')" />
                        <select id="tenant_ids" name="tenant_ids[]" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" multiple>
                            @foreach ($tenants as $tenant)
                                <option value="{{ $tenant->id }}" @selected(in_array($tenant->id, old('tenant_ids', [])))>{{ $tenant->fullName() }}</option>
                            @endforeach
                        </select>
                        <p class="text-xs text-gray-500 mt-1">{{ __('Strg/Cmd gedrückt halten, um mehrere Mieter auszuwählen.') }}</p>
                        <x-input-error :messages="$errors->get('tenant_ids')" class="mt-2" />
                        <a href="{{ route('tenants.create') }}" class="text-xs text-gray-500 hover:underline">{{ __('Neuer Mieter') }}</a>
                    </div>

                    <script>
                        (function () {
                            const toggleWrapper = document.getElementById('assignment-mode-toggle');
                            const referencePanel = document.querySelector('[data-assignment-panel="reference"]');
                            const tenantsPanel = document.querySelector('[data-assignment-panel="tenants"]');
                            const referenceSelect = document.getElementById('coupled_to_lease_id');

                            document.querySelectorAll('input[data-assignment-toggle]').forEach((input) => {
                                input.addEventListener('change', () => {
                                    document.querySelectorAll('[data-assignment-panel]').forEach((panel) => {
                                        panel.classList.toggle('hidden', panel.dataset.assignmentPanel !== input.value);
                                    });
                                });
                            });

                            // Scopes "management_unit"/"global" only pick the Mieteinheit via this
                            // <select> at submit time, so the coupling option (and its candidate
                            // list) can only be decided once a Mieteinheit is actually selected.
                            const rentalUnitSelect = document.getElementById('rental_unit_id');
                            const referenceableLeasesByRentalUnit = @json($referenceableLeasesByRentalUnit ?? null);

                            if (rentalUnitSelect && referenceableLeasesByRentalUnit !== null) {
                                const applyForSelectedRentalUnit = () => {
                                    const leases = referenceableLeasesByRentalUnit[rentalUnitSelect.value] ?? [];

                                    referenceSelect.innerHTML = '';
                                    const placeholder = document.createElement('option');
                                    placeholder.value = '';
                                    placeholder.disabled = true;
                                    placeholder.selected = true;
                                    placeholder.textContent = @json(__('Bitte wählen'));
                                    referenceSelect.appendChild(placeholder);
                                    leases.forEach((lease) => {
                                        const option = document.createElement('option');
                                        option.value = lease.id;
                                        option.textContent = lease.label;
                                        referenceSelect.appendChild(option);
                                    });

                                    // Restore a previously chosen referenced Mietvertrag after a
                                    // failed submit (validation error redisplay).
                                    const oldCoupledToLeaseId = @json(old('coupled_to_lease_id'));
                                    if (oldCoupledToLeaseId && leases.some((lease) => String(lease.id) === String(oldCoupledToLeaseId))) {
                                        referenceSelect.value = oldCoupledToLeaseId;
                                    }

                                    if (leases.length > 0) {
                                        toggleWrapper.classList.remove('hidden');
                                    } else {
                                        toggleWrapper.classList.add('hidden');
                                        document.querySelector('input[name="assignment_mode"][value="tenants"]').checked = true;
                                        tenantsPanel.classList.remove('hidden');
                                        referencePanel.classList.add('hidden');
                                    }
                                };

                                rentalUnitSelect.addEventListener('change', applyForSelectedRentalUnit);

                                if (rentalUnitSelect.value) {
                                    applyForSelectedRentalUnit();
                                }
                            }
                        })();
                    </script>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="start_date" :value="__('Startdatum')" />
                            <x-text-input id="start_date" name="start_date" type="date" class="mt-1 block w-full" :value="old('start_date')" required />
                            <x-input-error :messages="$errors->get('start_date')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="end_date" :value="__('Enddatum (optional)')" />
                            <x-text-input id="end_date" name="end_date" type="date" class="mt-1 block w-full" :value="old('end_date')" />
                            <x-input-error :messages="$errors->get('end_date')" class="mt-2" />
                        </div>
                    </div>

                    <div>
                        <label for="is_pre_contract" class="inline-flex items-center">
                            <input type="hidden" name="is_pre_contract" value="0">
                            <input type="checkbox" id="is_pre_contract" name="is_pre_contract" value="1" class="rounded border-gray-300 text-indigo-600 shadow-sm" @checked(old('is_pre_contract')) />
                            <span class="ms-2 text-sm text-gray-700">{{ __('Vorvertrag (wird nicht separat abgerechnet, geht im nachfolgenden Mietvertrag auf)') }}</span>
                        </label>
                        <x-input-error :messages="$errors->get('is_pre_contract')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="rent_adjustment_type" :value="__('Art der Mietanpassung')" />
                        <select id="rent_adjustment_type" name="rent_adjustment_type" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                            <option value="fixed" @selected(old('rent_adjustment_type', 'fixed') === 'fixed')>{{ __('Fix') }}</option>
                            <option value="stepped" @selected(old('rent_adjustment_type') === 'stepped')>{{ __('Staffelmiete') }}</option>
                            <option value="indexed" @selected(old('rent_adjustment_type') === 'indexed')>{{ __('Indexmiete') }}</option>
                        </select>
                        <x-input-error :messages="$errors->get('rent_adjustment_type')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="cold_rent" :value="__('Kaltmiete')" />
                        <x-text-input id="cold_rent" name="cold_rent" type="number" step="0.01" class="mt-1 block w-full" :value="old('cold_rent')" required />
                        <x-input-error :messages="$errors->get('cold_rent')" class="mt-2" />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="deposit_amount" :value="__('Kaution (optional)')" />
                            <x-text-input id="deposit_amount" name="deposit_amount" type="number" step="0.01" class="mt-1 block w-full" :value="old('deposit_amount')" />
                            <x-input-error :messages="$errors->get('deposit_amount')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="min_term_months" :value="__('Mindestlaufzeit (Monate)')" />
                            <x-text-input id="min_term_months" name="min_term_months" type="number" min="0" class="mt-1 block w-full" :value="old('min_term_months', 0)" />
                            <x-input-error :messages="$errors->get('min_term_months')" class="mt-2" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="notice_period" :value="__('Kündigungsfrist (optional)')" />
                        <x-text-input id="notice_period" name="notice_period" type="text" class="mt-1 block w-full" :value="old('notice_period')" />
                        <x-input-error :messages="$errors->get('notice_period')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="notes" :value="__('Notizen (optional)')" />
                        <textarea id="notes" name="notes" rows="3" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">{{ old('notes') }}</textarea>
                        <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>{{ __('Save') }}</x-primary-button>
                        @if ($scope === 'rental_unit')
                            <a href="{{ route('rental-units.show', $rentalUnit) }}" class="text-sm text-gray-600 hover:underline">{{ __('Abbrechen') }}</a>
                        @elseif ($scope === 'management_unit')
                            <a href="{{ route('management-units.show', $managementUnit) }}" class="text-sm text-gray-600 hover:underline">{{ __('Abbrechen') }}</a>
                        @else
                            <a href="{{ route('leases.index') }}" class="text-sm text-gray-600 hover:underline">{{ __('Abbrechen') }}</a>
                        @endif
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
