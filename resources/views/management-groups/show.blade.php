<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ $managementGroup->description ?: __('Verwaltungsgruppe') . ' #' . $managementGroup->id }}
            </h2>
            <div class="space-x-3">
                <a href="{{ route('management-groups.tax-overview', $managementGroup) }}" class="text-sm text-gray-600 hover:underline">{{ __('Steuerübersicht') }}</a>
                <a href="{{ route('management-groups.landlord.edit', $managementGroup) }}" class="text-sm text-gray-600 hover:underline">{{ __('Vermieter') }}</a>
                <a href="{{ route('management-groups.edit', $managementGroup) }}" class="text-sm text-gray-600 hover:underline">{{ __('Bearbeiten') }}</a>
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[1770px] mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-semibold">{{ __('Verwaltungseinheiten') }}</h3>
                    <a href="{{ route('management-units.create', ['management_group_id' => $managementGroup->id]) }}" class="text-sm text-gray-600 hover:underline">
                        {{ __('Neue Verwaltungseinheit') }}
                    </a>
                </div>
                <ul class="divide-y text-sm">
                    @forelse ($managementGroup->managementUnits as $managementUnit)
                        <li class="py-2">
                            <a href="{{ route('management-units.show', $managementUnit) }}" class="hover:underline">
                                {{ $managementUnit->street }}, {{ $managementUnit->postal_code }} {{ $managementUnit->city }}
                            </a>
                        </li>
                    @empty
                        <li class="py-2 text-gray-500">{{ __('Keine Verwaltungseinheiten vorhanden.') }}</li>
                    @endforelse
                </ul>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-semibold">{{ __('Abrechnungsintervalle') }}</h3>
                    <a href="{{ route('billing-intervals.create', ['management_group_id' => $managementGroup->id]) }}" class="text-sm text-gray-600 hover:underline">
                        {{ __('Neues Abrechnungsintervall') }}
                    </a>
                </div>
                <ul class="divide-y text-sm">
                    @foreach ($billingIntervals as $billingInterval)
                        <li class="py-2">
                            <div class="flex justify-between">
                                <span>{{ $billingInterval->start_date->format('d.m.Y') }} &ndash; {{ $billingInterval->end_date->format('d.m.Y') }}</span>
                                <span class="text-gray-500">{{ $billingInterval->description }}</span>
                            </div>

                            <details class="mt-2">
                                <summary class="cursor-pointer text-gray-600">{{ __('Übersicht aller Buchungen') }}</summary>
                                @include('financials._bookings-by-category', ['bookings' => $bookingsByInterval[$billingInterval->id]])
                            </details>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <details id="kostenkategorien">
                    <summary class="cursor-pointer text-sm font-semibold text-gray-700">{{ __('Abrechnungsschlüssel je Kostenkategorie') }}</summary>
                    <table class="w-full text-sm text-left mt-3">
                        <tbody class="divide-y">
                            @foreach ($costCategories as $costCategory)
                                @php($assigned = $managementGroup->costCategories->firstWhere('id', $costCategory->id))
                                <tr>
                                    <td class="py-2" style="padding-left: {{ $costCategory->depth() * 1.25 }}rem">{{ $costCategory->isSubcategory() ? '└ '.$costCategory->displayName() : $costCategory->name }}</td>
                                    <td class="py-2">
                                        @if ($costCategory->is_relevant_for_billing)
                                            <form method="POST" action="{{ route('management-groups.cost-categories.update', [$managementGroup, $costCategory]) }}">
                                                @csrf
                                                @method('PATCH')
                                                <select name="allocation_key" class="border-gray-300 rounded-md shadow-sm text-sm" onchange="this.form.submit()">
                                                    <option value="person_count" @selected($assigned?->pivot?->allocation_key === 'person_count')>{{ __('Personenzahl') }}</option>
                                                    <option value="area" @selected(!$assigned || $assigned->pivot->allocation_key === 'area')>{{ __('Fläche') }}</option>
                                                    <option value="individual" @selected($assigned?->pivot?->allocation_key === 'individual')>{{ __('Individuell') }}</option>
                                                    <option value="rental_unit" @selected($assigned?->pivot?->allocation_key === 'rental_unit')>{{ __('Mieteinheit') }}</option>
                                                </select>
                                            </form>
                                        @else
                                            {{-- Nicht abrechnungsrelevante Kategorien (z. B. Zinsen, Tilgung) werden nie
                                                 nach Fläche/Personen/… auf Mieteinheiten verteilt, brauchen also keinen
                                                 Abrechnungsschlüssel. --}}
                                            <span class="text-gray-400">{{ __('Nicht abrechnungsrelevant') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </details>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-semibold">{{ __('Wartungsverträge') }}</h3>
                    <a href="{{ route('maintenance-contracts.create', ['management_group_id' => $managementGroup->id]) }}" class="text-sm text-gray-600 hover:underline">
                        {{ __('Neuer Wartungsvertrag') }}
                    </a>
                </div>
                <table class="w-full text-sm text-left">
                    <thead class="text-gray-600">
                        <tr>
                            <th class="py-2">{{ __('Name') }}</th>
                            <th class="py-2">{{ __('Typ') }}</th>
                            <th class="py-2">{{ __('Anbieter') }}</th>
                            <th class="py-2">{{ __('Laufzeit') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse ($managementGroup->maintenanceContracts as $contract)
                            <tr>
                                <td class="py-2">
                                    <a href="{{ route('maintenance-contracts.edit', $contract) }}" class="hover:underline">{{ $contract->name }}</a>
                                </td>
                                <td class="py-2">{{ $contract->type }}</td>
                                <td class="py-2">{{ $contract->provider }}</td>
                                <td class="py-2">{{ $contract->start_date->format('d.m.Y') }} &ndash; {{ optional($contract->end_date)->format('d.m.Y') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-2 text-gray-500">{{ __('Keine Wartungsverträge vorhanden.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($managementGroup->kontosystemEnabled())
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="font-semibold">{{ __('Konten') }}</h3>
                        <div class="space-x-3">
                            <a href="{{ route('account-overview.show', $managementGroup) }}" class="text-sm text-gray-600 hover:underline">
                                {{ __('Kontoübersicht') }}
                            </a>
                            <a href="{{ route('accounts.create', ['management_group_id' => $managementGroup->id]) }}" class="text-sm text-gray-600 hover:underline">
                                {{ __('Neues Konto') }}
                            </a>
                        </div>
                    </div>
                    <table class="w-full text-sm text-left mb-6">
                        <thead class="text-gray-600">
                            <tr>
                                <th class="py-2">{{ __('Name') }}</th>
                                <th class="py-2">{{ __('Initialwert') }}</th>
                                <th class="py-2">{{ __('Default') }}</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @forelse ($managementGroup->accounts as $account)
                                <tr>
                                    <td class="py-2">
                                        <a href="{{ route('accounts.edit', $account) }}" class="hover:underline">{{ $account->name }}</a>
                                        @if ($account->description)
                                            <p class="text-xs text-gray-500">{{ $account->description }}</p>
                                        @endif
                                    </td>
                                    <td class="py-2">{{ euro($account->initial_balance) }} €</td>
                                    <td class="py-2">{{ $account->is_default ? __('Ja') : __('Nein') }}</td>
                                    <td class="py-2">
                                        <form method="POST" action="{{ route('accounts.destroy', $account) }}" onsubmit="return confirm('{{ __('Konto wirklich löschen?') }}')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-600 hover:underline">{{ __('Entfernen') }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="py-2 text-gray-500">{{ __('Keine Konten vorhanden.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>

                    @if ($managementGroup->accounts->count() >= 2)
                        <h4 class="font-medium text-sm mb-2">{{ __('Geld zwischen Konten verschieben') }}</h4>
                        <form method="POST" action="{{ route('account-transfers.store') }}" class="grid grid-cols-2 gap-3 items-end">
                            @csrf
                            <input type="hidden" name="management_group_id" value="{{ $managementGroup->id }}">

                            <div>
                                <x-input-label for="from_account_id" :value="__('Von Konto')" />
                                <select id="from_account_id" name="from_account_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                                    @foreach ($managementGroup->accounts as $account)
                                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <x-input-label for="to_account_id" :value="__('Zu Konto')" />
                                <select id="to_account_id" name="to_account_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                                    @foreach ($managementGroup->accounts as $account)
                                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <x-input-label for="transfer_amount" :value="__('Betrag')" />
                                <x-text-input id="transfer_amount" name="amount" type="number" step="0.01" class="mt-1 block w-full" required />
                            </div>

                            <div>
                                <x-input-label for="transfer_date" :value="__('Datum')" />
                                <x-text-input id="transfer_date" name="transfer_date" type="date" class="mt-1 block w-full" :value="now()->toDateString()" required />
                            </div>

                            <div class="col-span-2">
                                <x-input-label for="transfer_comment" :value="__('Kommentar (optional)')" />
                                <x-text-input id="transfer_comment" name="comment" type="text" class="mt-1 block w-full" />
                            </div>

                            <div class="col-span-2">
                                <x-input-error :messages="$errors->get('to_account_id')" class="mt-2" />
                                <x-primary-button>{{ __('Verschieben') }}</x-primary-button>
                            </div>
                        </form>
                    @endif
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <details id="belegungsplan" {{ $occupancyPlanOpen ? 'open' : '' }}>
                    <summary class="cursor-pointer text-sm font-semibold text-gray-700">{{ __('Belegungsplan') }}</summary>
                    <div class="mt-3">
                        @include('financials._year-switcher', ['planSwitcher' => true, 'anchor' => 'belegungsplan'])
                        @include('financials._occupancy-plan-table')
                    </div>
                </details>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                @include('financials._year-switcher')

                <h3 class="font-semibold mb-3">{{ __('Prognostizierte Einnahmen nach Mieteinheit-Typ') }}</h3>
                @include('financials._income-by-type-table')

                <h3 class="font-semibold mb-3 mt-8">{{ __('Gebuchte Einnahmen nach Mieteinheit-Typ') }}</h3>
                @include('financials._income-by-type-table', ['income' => $bookedIncome])

                <h3 class="font-semibold mb-3 mt-8">{{ __('Prognostizierte Ausgaben') }}</h3>
                @include('financials._expense-table')

                <h3 class="font-semibold mb-3 mt-8">{{ __('Gebuchte Ausgaben') }}</h3>
                @include('financials._expense-table', ['expenses' => $bookedExpenses])
            </div>
        </div>
    </div>
</x-app-layout>
