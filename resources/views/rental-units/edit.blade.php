<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Mieteinheit bearbeiten') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[691px] mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <form method="POST" action="{{ route('rental-units.update', $rentalUnit) }}" class="space-y-4">
                    @csrf
                    @method('PUT')

                    <div>
                        <x-input-label for="type" :value="__('Typ')" />
                        <select id="type" name="type" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                            <option value="Wohnung" @selected(old('type', $rentalUnit->type) === 'Wohnung')>{{ __('Wohnung') }}</option>
                            <option value="Garage" @selected(old('type', $rentalUnit->type) === 'Garage')>{{ __('Garage') }}</option>
                            <option value="Stellplatz" @selected(old('type', $rentalUnit->type) === 'Stellplatz')>{{ __('Stellplatz') }}</option>
                        </select>
                        <x-input-error :messages="$errors->get('type')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="description" :value="__('Beschreibung')" />
                        <x-text-input id="description" name="description" type="text" class="mt-1 block w-full" :value="old('description', $rentalUnit->description)" placeholder="{{ __('z. B. Erdgeschoss, Stellplatz 5') }}" />
                        <x-input-error :messages="$errors->get('description')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="floor" :value="__('Etage (optional)')" />
                        <x-text-input id="floor" name="floor" type="number" step="1" class="mt-1 block w-full" :value="old('floor', $rentalUnit->floor)" placeholder="{{ __('z. B. 0 für EG, 1 für 1. OG') }}" />
                        <x-input-error :messages="$errors->get('floor')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="size" :value="__('Größe (m²)')" />
                        <x-text-input id="size" name="size" type="number" step="0.01" class="mt-1 block w-full" :value="old('size', $rentalUnit->size)" />
                        <x-input-error :messages="$errors->get('size')" class="mt-2" />
                    </div>

                    <div class="flex items-center gap-2">
                        <input type="hidden" name="is_relevant_for_billing" value="0">
                        <input id="is_relevant_for_billing" type="checkbox" name="is_relevant_for_billing" value="1" @checked(old('is_relevant_for_billing', $rentalUnit->is_relevant_for_billing))>
                        <x-input-label for="is_relevant_for_billing" :value="__('Relevant für Betriebskostenabrechnung')" />
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>{{ __('Save') }}</x-primary-button>
                        <a href="{{ route('rental-units.show', $rentalUnit) }}" class="text-sm text-gray-600 hover:underline">{{ __('Abbrechen') }}</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
