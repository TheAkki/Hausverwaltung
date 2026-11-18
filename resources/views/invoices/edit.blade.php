<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Rechnung bearbeiten') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[1536px] mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="max-w-[691px]">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <form method="POST" action="{{ route('invoices.update', $invoice) }}" enctype="multipart/form-data" class="space-y-4">
                        @csrf
                        @method('PUT')
                        @include('invoices._form')

                        <div>
                            <x-input-label for="amount" :value="__('Rechnungsbetrag')" />
                            <x-text-input id="amount" name="amount" type="number" step="0.01" class="mt-1 block w-full" :value="old('amount', $invoice->amount)" required />
                            <x-input-error :messages="$errors->get('amount')" class="mt-2" />
                        </div>

                        <div class="flex items-center gap-4">
                            <x-primary-button>{{ __('Save') }}</x-primary-button>
                            <a href="{{ route('invoices.show', $invoice) }}" class="text-sm text-gray-600 hover:underline">{{ __('Abbrechen') }}</a>
                        </div>
                    </form>
                </div>
            </div>

            @include('invoices._costs')
        </div>
    </div>
</x-app-layout>
