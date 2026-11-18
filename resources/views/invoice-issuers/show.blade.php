<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ $invoiceIssuer->fullName() }}
                @if ($invoiceIssuer->company_name)
                    <span class="text-base font-normal text-gray-500">({{ $invoiceIssuer->company_name }})</span>
                @endif
            </h2>
            <a href="{{ route('invoice-issuers.edit', $invoiceIssuer) }}" class="text-sm text-gray-600 hover:underline">{{ __('Bearbeiten') }}</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[691px] mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <p class="text-sm text-gray-700">{{ $invoiceIssuer->fullAddress() }}</p>
                @if ($invoiceIssuer->email || $invoiceIssuer->phone || $invoiceIssuer->mobile_phone)
                    <p class="text-sm text-gray-700 mt-1">
                        @if ($invoiceIssuer->email) {{ $invoiceIssuer->email }} @endif
                        @if ($invoiceIssuer->phone) &middot; {{ $invoiceIssuer->phone }} @endif
                        @if ($invoiceIssuer->mobile_phone) &middot; {{ $invoiceIssuer->mobile_phone }} @endif
                    </p>
                @endif
                @if ($invoiceIssuer->iban || $invoiceIssuer->bic)
                    <p class="text-sm text-gray-500 mt-1">
                        @if ($invoiceIssuer->iban) IBAN: {{ \App\Support\IbanFormatter::format($invoiceIssuer->iban) }} @endif
                        @if ($invoiceIssuer->bic) &middot; BIC: {{ $invoiceIssuer->bic }} @endif
                    </p>
                @endif
                @if ($invoiceIssuer->comment)
                    <p class="text-sm text-gray-500 mt-2">{{ $invoiceIssuer->comment }}</p>
                @endif
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <div class="flex justify-between items-center mb-3">
                    <h3 class="font-semibold">{{ __('Kundenaccounts') }}</h3>
                    <a href="{{ route('invoice-issuer-accounts.create', ['invoice_issuer_id' => $invoiceIssuer->id]) }}" class="text-sm text-gray-600 hover:underline">
                        {{ __('Neuer Kundenaccount') }}
                    </a>
                </div>
                <ul class="text-sm divide-y">
                    @forelse ($invoiceIssuer->accounts as $account)
                        <li class="py-2 flex justify-between items-center">
                            <span>
                                {{ $account->customer_number }}
                                @if ($account->description) &mdash; {{ $account->description }} @endif
                                @if ($account->direct_debit) <span class="text-gray-500">({{ __('Lastschrift') }})</span> @endif
                                @if ($account->notes) <span class="block text-xs text-gray-500">{{ $account->notes }}</span> @endif
                            </span>
                            <a href="{{ route('invoice-issuer-accounts.edit', $account) }}" class="text-gray-600 hover:underline">{{ __('Bearbeiten') }}</a>
                        </li>
                    @empty
                        <li class="py-2 text-gray-500">{{ __('Keine Kundenaccounts vorhanden.') }}</li>
                    @endforelse
                </ul>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold mb-3">{{ __('Rechnungen') }}</h3>
                <ul class="text-sm divide-y">
                    @forelse ($invoiceIssuer->invoices as $invoice)
                        <li class="py-2">
                            <a href="{{ route('invoices.show', $invoice) }}" class="hover:underline">
                                {{ $invoice->invoice_date->format('d.m.Y') }} &mdash; {{ euro($invoice->amount) }} €
                            </a>
                        </li>
                    @empty
                        <li class="py-2 text-gray-500">{{ __('Keine Rechnungen vorhanden.') }}</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</x-app-layout>
