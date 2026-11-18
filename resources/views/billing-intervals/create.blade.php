<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Neues Abrechnungsintervall') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[691px] mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                @if ($latest)
                    <p class="text-sm text-gray-500 mb-1">
                        {{ __('Letztes Intervall endet am') }} {{ $latest->end_date->format('d.m.Y') }}.
                        {{ __('Ein neues Intervall kann nahtlos daran anschließen (Startdatum s.u.)') }}.
                    </p>
                @endif
                @if ($earliest)
                    <p class="text-sm text-gray-500 mb-1">
                        {{ __('Erstes Intervall beginnt am') }} {{ $earliest->start_date->format('d.m.Y') }}.
                        {{ __('Um ein früheres Intervall davor einzufügen, wähle als Enddatum den Tag davor.') }}
                    </p>
                @endif
                @if ($managementGroup->billing_start)
                    <p class="text-sm text-gray-500 mb-4">
                        {{ __('Der Beginn des Abrechnungsintervalls der Verwaltungsgruppe ist') }} {{ $managementGroup->billing_start->format('d.m.Y') }}.
                        {{ __('Kein Abrechnungsintervall darf davor beginnen.') }}
                    </p>
                @endif

                <form method="POST" action="{{ route('billing-intervals.store') }}" class="space-y-4">
                    @csrf
                    <input type="hidden" name="management_group_id" value="{{ $managementGroup->id }}">

                    <div>
                        <x-input-label for="start_date" :value="__('Startdatum')" />
                        <x-text-input id="start_date" name="start_date" type="date" class="mt-1 block w-full" :value="old('start_date', $latest ? $latest->end_date->addDay()->toDateString() : '')" required />
                        <x-input-error :messages="$errors->get('start_date')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="end_date" :value="__('Enddatum (Standard: 31.12. des Startjahres)')" />
                        <x-text-input id="end_date" name="end_date" type="date" class="mt-1 block w-full" :value="old('end_date')" />
                        <x-input-error :messages="$errors->get('end_date')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="description" :value="__('Beschreibung')" />
                        <textarea id="description" name="description" rows="3" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">{{ old('description') }}</textarea>
                        <x-input-error :messages="$errors->get('description')" class="mt-2" />
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
