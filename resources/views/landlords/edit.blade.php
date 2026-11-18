<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Vermieter') }} &ndash; {{ $managementGroup->description }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[691px] mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 space-y-4">
                <h3 class="font-semibold">{{ __('Vermieter dieser Verwaltungsgruppe (Überschreibung)') }}</h3>
                <p class="text-sm text-gray-600">
                    {{ __('Ist hier ein Vermieter hinterlegt, wird er auf der Betriebskostenabrechnung dieser Verwaltungsgruppe verwendet – andernfalls der Vermieter des Accounts (unten).') }}
                </p>

                @if ($overrideLandlord)
                    <form method="POST" action="{{ route('management-groups.landlord.update', $managementGroup) }}" class="mb-2">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="scope" value="override">
                        <input type="hidden" name="remove_override" value="1">
                        <button type="submit" class="text-sm text-red-600 hover:underline">{{ __('Überschreibung entfernen (Account-Vermieter verwenden)') }}</button>
                    </form>
                @endif

                <form method="POST" action="{{ route('management-groups.landlord.update', $managementGroup) }}" class="space-y-4">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="scope" value="override">
                    @include('landlords._fields', ['landlord' => $overrideLandlord, 'prefix' => 'override'])
                    <x-primary-button>{{ __('Überschreibung speichern') }}</x-primary-button>
                </form>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 space-y-4">
                <h3 class="font-semibold">{{ __('Vermieter des Accounts') }}</h3>
                <p class="text-sm text-gray-600">
                    {{ __('Wird für alle Verwaltungsgruppen dieses Accounts verwendet, sofern sie keine eigene Überschreibung haben.') }}
                </p>
                <form method="POST" action="{{ route('management-groups.landlord.update', $managementGroup) }}" class="space-y-4">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="scope" value="account">
                    @include('landlords._fields', ['landlord' => $accountLandlord, 'prefix' => 'account'])
                    <x-primary-button>{{ __('Account-Vermieter speichern') }}</x-primary-button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
