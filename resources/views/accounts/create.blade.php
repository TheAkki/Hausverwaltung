<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Neues Konto') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[691px] mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <form method="POST" action="{{ route('accounts.store') }}" class="space-y-4">
                    @csrf
                    <input type="hidden" name="management_group_id" value="{{ $managementGroup->id }}">

                    <div>
                        <x-input-label for="name" :value="__('Name')" />
                        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name')" required autofocus />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="description" :value="__('Beschreibung')" />
                        <textarea id="description" name="description" rows="3" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">{{ old('description') }}</textarea>
                        <x-input-error :messages="$errors->get('description')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="initial_balance" :value="__('Buchungsneutraler Initialwert')" />
                        <x-text-input id="initial_balance" name="initial_balance" type="number" step="0.01" class="mt-1 block w-full" :value="old('initial_balance', 0)" />
                        <x-input-error :messages="$errors->get('initial_balance')" class="mt-2" />
                    </div>

                    <div class="flex items-center gap-2">
                        <input id="is_default" name="is_default" type="checkbox" value="1" class="rounded border-gray-300 shadow-sm" {{ old('is_default', $managementGroup->accounts->isEmpty()) ? 'checked' : '' }}>
                        <x-input-label for="is_default" :value="__('Als Defaultkonto markieren')" class="!mb-0" />
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>{{ __('Save') }}</x-primary-button>
                        <a href="{{ route('management-groups.show', $managementGroup) }}" class="text-sm text-gray-600 hover:underline">{{ __('Abbrechen') }}</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
