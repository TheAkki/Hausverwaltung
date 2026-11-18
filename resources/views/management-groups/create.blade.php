<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Neue Verwaltungsgruppe') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[691px] mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <form method="POST" action="{{ route('management-groups.store') }}" class="space-y-4">
                    @csrf

                    <div>
                        <x-input-label for="user_group_id" :value="__('Benutzergruppe')" />
                        <select id="user_group_id" name="user_group_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                            @foreach ($userGroups as $userGroup)
                                <option value="{{ $userGroup->id }}" @selected(old('user_group_id') == $userGroup->id)>{{ $userGroup->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('user_group_id')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="description" :value="__('Beschreibung')" />
                        <textarea id="description" name="description" rows="3" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">{{ old('description') }}</textarea>
                        <x-input-error :messages="$errors->get('description')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="billing_start" :value="__('Abrechnungsintervall-Beginn (optional, falls nicht 1. Januar)')" />
                        <x-text-input id="billing_start" name="billing_start" type="date" class="mt-1 block w-full" :value="old('billing_start')" />
                        <x-input-error :messages="$errors->get('billing_start')" class="mt-2" />
                    </div>

                    <div class="flex items-center gap-2">
                        <input id="kontosystem_enabled" name="kontosystem_enabled" type="checkbox" value="1" class="rounded border-gray-300 shadow-sm" {{ old('kontosystem_enabled') ? 'checked' : '' }}>
                        <x-input-label for="kontosystem_enabled" :value="__('Kontensystem aktivieren')" class="!mb-0" />
                    </div>

                    @include('management-groups._variable-cost-forecast-mode-fields', ['selected' => old('variable_cost_forecast_mode', \App\Models\ManagementGroup::VARIABLE_FORECAST_LAST_BOOKING)])

                    <div class="flex items-center gap-4">
                        <x-primary-button>{{ __('Save') }}</x-primary-button>
                        <a href="{{ route('management-groups.index') }}" class="text-sm text-gray-600 hover:underline">{{ __('Abbrechen') }}</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
