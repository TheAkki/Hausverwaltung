<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Forderung bearbeiten') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[691px] mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <form method="POST" action="{{ route('settlement-claims.update', $settlementClaim) }}" class="space-y-4">
                    @csrf
                    @method('PUT')

                    <div>
                        <x-input-label for="billing_interval_id" :value="__('Abrechnungsintervall')" />
                        <select id="billing_interval_id" name="billing_interval_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                            @foreach ($billingIntervals as $billingInterval)
                                <option value="{{ $billingInterval->id }}" @selected((int) old('billing_interval_id', $settlementClaim->billing_interval_id) === $billingInterval->id)>{{ $billingInterval->start_date->format('d.m.Y') }} &ndash; {{ $billingInterval->end_date->format('d.m.Y') }} @if ($billingInterval->description) ({{ $billingInterval->description }}) @endif</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('billing_interval_id')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="date" :value="__('Datum')" />
                        <x-text-input id="date" name="date" type="date" class="mt-1 block w-full" :value="old('date', $settlementClaim->date->toDateString())" required />
                        <x-input-error :messages="$errors->get('date')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="amount" :value="__('Betrag')" />
                        <x-text-input id="amount" name="amount" type="number" step="0.01" class="mt-1 block w-full" :value="old('amount', $settlementClaim->amount)" required />
                        <p class="text-xs text-gray-500 mt-1">{{ __('Positiver Betrag = Nachzahlung, negativer Betrag = Rückzahlung.') }}</p>
                        <x-input-error :messages="$errors->get('amount')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="booking_date" :value="__('Buchungsdatum (optional)')" />
                        <x-text-input id="booking_date" name="booking_date" type="date" class="mt-1 block w-full" :value="old('booking_date', optional($settlementClaim->booking_date)->toDateString())" />
                        <x-input-error :messages="$errors->get('booking_date')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="notes" :value="__('Notizen (optional)')" />
                        <textarea id="notes" name="notes" rows="3" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">{{ old('notes', $settlementClaim->notes) }}</textarea>
                        <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>{{ __('Save') }}</x-primary-button>
                        @if ($lease)
                            <a href="{{ route('leases.show', $lease) }}#forderungen" class="text-sm text-gray-600 hover:underline">{{ __('Abbrechen') }}</a>
                        @else
                            <a href="{{ route('management-units.show', $rentalUnit->managementUnit) }}#leerstand" class="text-sm text-gray-600 hover:underline">{{ __('Abbrechen') }}</a>
                        @endif
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
