<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ $tenant->fullName() }}
                @if ($tenant->company_name)
                    <span class="text-base font-normal text-gray-500">({{ $tenant->company_name }})</span>
                @endif
            </h2>
            <a href="{{ route('tenants.edit', $tenant) }}" class="text-sm text-gray-600 hover:underline">{{ __('Bearbeiten') }}</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[691px] mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                @if ($tenant->salutation)
                    <p class="text-sm text-gray-500">{{ $tenant->salutation }}</p>
                @endif
                <p class="text-sm text-gray-700">{{ $tenant->street }}, {{ $tenant->postal_code }} {{ $tenant->city }}</p>
                @if ($tenant->email || $tenant->mobile_phone)
                    <p class="text-sm text-gray-500 mt-2">
                        @if ($tenant->email) {{ $tenant->email }} @endif
                        @if ($tenant->mobile_phone) &middot; {{ $tenant->mobile_phone }} @endif
                    </p>
                @endif
                @if ($tenant->iban || $tenant->bic)
                    <p class="text-sm text-gray-500 mt-2">
                        @if ($tenant->iban) IBAN: {{ \App\Support\IbanFormatter::format($tenant->iban) }} @endif
                        @if ($tenant->bic) &middot; BIC: {{ $tenant->bic }} @endif
                    </p>
                @endif
            </div>

            @if ($tenant->notes)
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <h3 class="font-semibold mb-3">{{ __('Notizen') }}</h3>
                    <p class="text-sm text-gray-700 whitespace-pre-line">{{ $tenant->notes }}</p>
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold mb-3">{{ __('Mietverträge') }}</h3>
                <ul class="text-sm divide-y">
                    @forelse ($tenant->leases as $lease)
                        <li class="py-2">
                            <a href="{{ route('leases.show', $lease) }}" class="hover:underline">
                                {{ $lease->rentalUnit->managementUnit->street }} &mdash; {{ $lease->rentalUnit->label() }}
                            </a>
                        </li>
                    @empty
                        <li class="py-2 text-gray-500">{{ __('Keine Mietverträge vorhanden.') }}</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</x-app-layout>
