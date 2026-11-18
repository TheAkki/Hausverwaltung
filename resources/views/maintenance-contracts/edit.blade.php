<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Wartungsvertrag bearbeiten') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[691px] mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <form method="POST" action="{{ route('maintenance-contracts.update', $maintenanceContract) }}" class="space-y-4">
                    @csrf
                    @method('PUT')

                    <div>
                        <x-input-label for="name" :value="__('Name')" />
                        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $maintenanceContract->name)" required autofocus />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="type" :value="__('Typ')" />
                        <x-text-input id="type" name="type" type="text" class="mt-1 block w-full" :value="old('type', $maintenanceContract->type)" required />
                        <x-input-error :messages="$errors->get('type')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="provider" :value="__('Anbieter')" />
                        <x-text-input id="provider" name="provider" type="text" class="mt-1 block w-full" :value="old('provider', $maintenanceContract->provider)" required />
                        <x-input-error :messages="$errors->get('provider')" class="mt-2" />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="start_date" :value="__('Start')" />
                            <x-text-input id="start_date" name="start_date" type="date" class="mt-1 block w-full" :value="old('start_date', $maintenanceContract->start_date->toDateString())" required />
                            <x-input-error :messages="$errors->get('start_date')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="end_date" :value="__('Ende (optional)')" />
                            <x-text-input id="end_date" name="end_date" type="date" class="mt-1 block w-full" :value="old('end_date', optional($maintenanceContract->end_date)->toDateString())" />
                            <x-input-error :messages="$errors->get('end_date')" class="mt-2" />
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>{{ __('Save') }}</x-primary-button>
                        <a href="{{ route('management-groups.show', $maintenanceContract->managementGroup) }}" class="text-sm text-gray-600 hover:underline">{{ __('Abbrechen') }}</a>
                        <form method="POST" action="{{ route('maintenance-contracts.destroy', $maintenanceContract) }}" onsubmit="return confirm('{{ __('Wirklich löschen?') }}')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-sm text-red-600 hover:underline">{{ __('Löschen') }}</button>
                        </form>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
