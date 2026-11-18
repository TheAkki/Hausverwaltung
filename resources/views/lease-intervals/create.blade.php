<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Neues Mietintervall') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[691px] mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <form method="POST" action="{{ route('lease-intervals.store') }}" class="space-y-4">
                    @csrf
                    <input type="hidden" name="lease_id" value="{{ $lease->id }}">

                    <div>
                        <x-input-label for="start_date" :value="__('Startdatum')" />
                        <x-text-input id="start_date" name="start_date" type="date" class="mt-1 block w-full" :value="old('start_date')" required />
                        <x-input-error :messages="$errors->get('start_date')" class="mt-2" />
                        @if ($latest)
                            <p class="text-xs text-gray-500 mt-1">{{ __('Das vorherige Mietintervall endet am Tag vor diesem Startdatum.') }}</p>
                        @endif
                    </div>

                    <div>
                        <x-input-label for="rent_amount" :value="__('Miete')" />
                        <x-text-input id="rent_amount" name="rent_amount" type="number" step="0.01" class="mt-1 block w-full" :value="old('rent_amount', $latest->rent_amount ?? $lease->cold_rent)" required />
                        <x-input-error :messages="$errors->get('rent_amount')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="occupant_count" :value="__('Anzahl Personen')" />
                        <x-text-input id="occupant_count" name="occupant_count" type="number" class="mt-1 block w-full" :value="old('occupant_count', $latest->occupant_count ?? 1)" required />
                        <x-input-error :messages="$errors->get('occupant_count')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="utility_advance_payment" :value="__('Betriebskostenvorauszahlung')" />
                        <x-text-input id="utility_advance_payment" name="utility_advance_payment" type="number" step="0.01" class="mt-1 block w-full" :value="old('utility_advance_payment', $latest->utility_advance_payment ?? 0)" required />
                        <x-input-error :messages="$errors->get('utility_advance_payment')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="notes" :value="__('Notizen (optional)')" />
                        <textarea id="notes" name="notes" rows="3" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">{{ old('notes') }}</textarea>
                        <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>{{ __('Save') }}</x-primary-button>
                        <a href="{{ route('leases.show', $lease) }}" class="text-sm text-gray-600 hover:underline">{{ __('Abbrechen') }}</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
