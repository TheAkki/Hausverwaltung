<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Rechnungen') }}</h2>
            <a href="{{ route('invoices.create') }}" class="px-4 py-2 bg-gray-900 text-white rounded-md text-sm font-medium hover:bg-gray-700">
                {{ __('Neue Rechnung') }}
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[1536px] mx-auto sm:px-6 lg:px-8">
            @php
                $searchFields = ['issuer_id', 'issuer_account_id', 'date_from', 'date_to', 'amount_min', 'amount_max', 'cost_category_id', 'description', 'booking_date_from', 'booking_date_to', 'has_file', 'invoice_number'];
            @endphp
            <details class="mb-4 bg-white shadow-sm sm:rounded-lg p-4" {{ request()->hasAny($searchFields) ? 'open' : '' }}>
                <summary class="cursor-pointer font-semibold">{{ __('Rechnungen finden') }}</summary>
                <form method="GET" action="{{ route('invoices.index') }}" class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-4">
                    @if ($showCompleted)
                        <input type="hidden" name="show_completed" value="1">
                    @endif
                    <div>
                        <x-input-label for="issuer_id" :value="__('Rechnungssteller')" />
                        <select id="issuer_id" name="issuer_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                            <option value="">{{ __('Bitte wählen') }}</option>
                            @foreach ($invoiceIssuers as $issuer)
                                <option value="{{ $issuer->id }}" @selected((int) request('issuer_id') === $issuer->id)>{{ $issuer->fullName() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="issuer_account_id" :value="__('Account')" />
                        <select id="issuer_account_id" name="issuer_account_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                            <option value="">{{ __('Bitte wählen') }}</option>
                            @foreach ($accountsByIssuer as $accounts)
                                @foreach ($accounts as $account)
                                    <option value="{{ $account->id }}" @selected((int) request('issuer_account_id') === $account->id)>{{ $account->customer_number }}</option>
                                @endforeach
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label :value="__('Rechnungsdatum')" />
                        <div class="flex gap-2 mt-1">
                            <x-text-input type="date" name="date_from" :value="request('date_from')" class="w-full" />
                            <x-text-input type="date" name="date_to" :value="request('date_to')" class="w-full" />
                        </div>
                    </div>
                    <div>
                        <x-input-label :value="__('Betrag')" />
                        <div class="flex gap-2 mt-1">
                            <x-text-input type="number" step="0.01" name="amount_min" :value="request('amount_min')" class="w-full" />
                            <x-text-input type="number" step="0.01" name="amount_max" :value="request('amount_max')" class="w-full" />
                        </div>
                    </div>
                    <div>
                        <x-input-label for="cost_category_id" :value="__('Kategorie')" />
                        <select id="cost_category_id" name="cost_category_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                            <option value="">{{ __('Bitte wählen') }}</option>
                            @foreach ($costCategories as $category)
                                <option value="{{ $category->id }}" @selected((int) request('cost_category_id') === $category->id)>{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="description" :value="__('Beschreibung')" />
                        <x-text-input id="description" name="description" :value="request('description')" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="invoice_number" :value="__('Rechnungsnummer')" />
                        <x-text-input id="invoice_number" name="invoice_number" :value="request('invoice_number')" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label :value="__('Buchungsdatum')" />
                        <div class="flex gap-2 mt-1">
                            <x-text-input type="date" name="booking_date_from" :value="request('booking_date_from')" class="w-full" />
                            <x-text-input type="date" name="booking_date_to" :value="request('booking_date_to')" class="w-full" />
                        </div>
                    </div>
                    <div>
                        <x-input-label for="has_file" :value="__('Beleg (Datei) vorhanden')" />
                        <select id="has_file" name="has_file" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                            <option value="">{{ __('Bitte wählen') }}</option>
                            <option value="yes" @selected(request('has_file') === 'yes')>{{ __('Ja') }}</option>
                            <option value="no" @selected(request('has_file') === 'no')>{{ __('Nein') }}</option>
                        </select>
                    </div>
                    <div>
                        <x-input-label :value="__('Verknüpfung der Kriterien')" />
                        <div class="mt-2 flex gap-4 text-sm">
                            <label class="flex items-center gap-1"><input type="radio" name="match" value="all" @checked(request('match', 'all') !== 'any')> {{ __('UND') }}</label>
                            <label class="flex items-center gap-1"><input type="radio" name="match" value="any" @checked(request('match') === 'any')> {{ __('ODER') }}</label>
                        </div>
                    </div>
                    <div class="flex items-end gap-4">
                        <x-primary-button>{{ __('Suchen') }}</x-primary-button>
                        <a href="{{ route('invoices.index', $showCompleted ? ['show_completed' => 1] : []) }}" class="text-sm text-gray-600 hover:underline">{{ __('Zurücksetzen') }}</a>
                    </div>
                </form>
            </details>
            <div class="flex justify-end mb-3">
                <a href="{{ route('invoices.index', [...request()->except('show_completed'), ...($showCompleted ? [] : ['show_completed' => 1])]) }}" class="text-sm text-gray-600 hover:underline">
                    {{ $showCompleted ? __('Erledigte Rechnungen ausblenden') : __('Erledigte Rechnungen einblenden') }}
                </a>
            </div>
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="bg-gray-50 text-gray-600">
                        <tr>
                            <th class="px-6 py-3">{{ __('Rechnungsdatum') }}</th>
                            <th class="px-6 py-3">{{ __('Buchungsdatum oder Buchungsbeginn') }}</th>
                            <th class="px-6 py-3">{{ __('Buchungsende') }}</th>
                            <th class="px-6 py-3">{{ __('Rechnungssteller') }}</th>
                            <th class="px-6 py-3">{{ __('Beschreibung') }}</th>
                            <th class="px-6 py-3">{{ __('Betrag') }}</th>
                            <th class="px-6 py-3">{{ __('Periodizität') }}</th>
                            <th class="px-6 py-3">{{ __('Zugeordnet zu') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @foreach ($invoices as $invoice)
                            <tr class="{{ $invoice->isFullyBooked() ? 'text-gray-400' : '' }}">
                                <td class="px-6 py-3">
                                    <a href="{{ route('invoices.show', $invoice) }}" class="hover:underline">{{ $invoice->invoice_date->format('d.m.Y') }}</a>
                                </td>
                                <td class="px-6 py-3 text-gray-600">{{ optional($invoice->bookingRangeStart())->format('d.m.Y') }}</td>
                                <td class="px-6 py-3 text-gray-600">{{ optional($invoice->bookingRangeEnd())->format('d.m.Y') }}</td>
                                <td class="px-6 py-3">{{ $invoice->invoiceIssuer->fullName() }}</td>
                                <td class="px-6 py-3 text-gray-600">{{ $invoice->description }}</td>
                                <td class="px-6 py-3 whitespace-nowrap">{{ euro($invoice->amount) }} €</td>
                                <td class="px-6 py-3">{{ $invoice->periodicityLabel() }}</td>
                                <td class="px-6 py-3 text-gray-600">{{ $invoice->costableLabel() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            </div>
            <div class="mt-4">{{ $invoices->links() }}</div>
        </div>
    </div>
</x-app-layout>
