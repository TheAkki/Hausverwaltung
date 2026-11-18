<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Mietintervall bearbeiten') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[691px] mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <form method="POST" action="{{ route('lease-intervals.update', $leaseInterval) }}" class="space-y-4">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="start_date" :value="__('Startdatum')" />
                            <x-text-input id="start_date" name="start_date" type="date" class="mt-1 block w-full" :value="old('start_date', $leaseInterval->start_date->toDateString())" required />
                            <x-input-error :messages="$errors->get('start_date')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="end_date" :value="__('Enddatum')" />
                            <x-text-input id="end_date" name="end_date" type="date" class="mt-1 block w-full" :value="old('end_date', optional($leaseInterval->end_date)->toDateString())" />
                            <x-input-error :messages="$errors->get('end_date')" class="mt-2" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="rent_amount" :value="__('Miete')" />
                        <x-text-input id="rent_amount" name="rent_amount" type="number" step="0.01" class="mt-1 block w-full" :value="old('rent_amount', $leaseInterval->rent_amount)" required />
                        <x-input-error :messages="$errors->get('rent_amount')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="occupant_count" :value="__('Anzahl Personen')" />
                        <x-text-input id="occupant_count" name="occupant_count" type="number" class="mt-1 block w-full" :value="old('occupant_count', $leaseInterval->occupant_count)" required />
                        <x-input-error :messages="$errors->get('occupant_count')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="utility_advance_payment" :value="__('Betriebskostenvorauszahlung')" />
                        <x-text-input id="utility_advance_payment" name="utility_advance_payment" type="number" step="0.01" class="mt-1 block w-full" :value="old('utility_advance_payment', $leaseInterval->utility_advance_payment)" required />
                        <x-input-error :messages="$errors->get('utility_advance_payment')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="notes" :value="__('Notizen (optional)')" />
                        <textarea id="notes" name="notes" rows="3" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">{{ old('notes', $leaseInterval->notes) }}</textarea>
                        <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>{{ __('Save') }}</x-primary-button>
                        <a href="{{ route('leases.show', $leaseInterval->lease) }}" class="text-sm text-gray-600 hover:underline">{{ __('Abbrechen') }}</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
