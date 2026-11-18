<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Neuer Kundenaccount') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[691px] mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <form method="POST" action="{{ route('invoice-issuer-accounts.store') }}" class="space-y-4">
                    @csrf
                    <input type="hidden" name="invoice_issuer_id" value="{{ $invoiceIssuer->id }}">

                    <div>
                        <x-input-label :value="__('Rechnungssteller')" />
                        <p class="mt-1 text-sm text-gray-700">{{ $invoiceIssuer->fullName() }}</p>
                    </div>

                    <div>
                        <x-input-label for="customer_number" :value="__('Kundennummer')" />
                        <x-text-input id="customer_number" name="customer_number" type="text" class="mt-1 block w-full" :value="old('customer_number')" required autofocus />
                        <x-input-error :messages="$errors->get('customer_number')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="description" :value="__('Beschreibung (optional)')" />
                        <x-text-input id="description" name="description" type="text" class="mt-1 block w-full" :value="old('description')" placeholder="{{ __('z. B. Strom, Wasser, Fernwärme') }}" />
                        <x-input-error :messages="$errors->get('description')" class="mt-2" />
                    </div>

                    <div class="flex items-center gap-2">
                        <input type="hidden" name="direct_debit" value="0">
                        <input id="direct_debit" type="checkbox" name="direct_debit" value="1" @checked(old('direct_debit'))>
                        <x-input-label for="direct_debit" :value="__('Lastschrift')" />
                    </div>

                    <div>
                        <x-input-label for="notes" :value="__('Notizen (optional)')" />
                        <textarea id="notes" name="notes" rows="3" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">{{ old('notes') }}</textarea>
                        <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>{{ __('Save') }}</x-primary-button>
                        <a href="{{ route('invoice-issuers.show', $invoiceIssuer) }}" class="text-sm text-gray-600 hover:underline">{{ __('Abbrechen') }}</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
