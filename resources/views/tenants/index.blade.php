<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Mieter') }}</h2>
            <a href="{{ route('tenants.create') }}" class="px-4 py-2 bg-gray-900 text-white rounded-md text-sm font-medium hover:bg-gray-700">
                {{ __('Neuer Mieter') }}
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[1229px] mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <table class="w-full text-sm text-left">
                    <thead class="bg-gray-50 text-gray-600">
                        <tr>
                            <th class="px-6 py-3">{{ __('Name') }}</th>
                            <th class="px-6 py-3">{{ __('Anschrift') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @foreach ($tenants as $tenant)
                            <tr>
                                <td class="px-6 py-3">
                                    <a href="{{ route('tenants.show', $tenant) }}" class="hover:underline">{{ $tenant->fullName() }}</a>
                                </td>
                                <td class="px-6 py-3">{{ $tenant->street }}, {{ $tenant->postal_code }} {{ $tenant->city }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $tenants->links() }}</div>
        </div>
    </div>
</x-app-layout>
