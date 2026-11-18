<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Verwaltungsgruppen') }}</h2>
            <a href="{{ route('management-groups.create') }}" class="px-4 py-2 bg-gray-900 text-white rounded-md text-sm font-medium hover:bg-gray-700">
                {{ __('Neue Verwaltungsgruppe') }}
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[1229px] mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <ul class="divide-y">
                    @forelse ($managementGroups as $managementGroup)
                        <li class="px-6 py-4 flex justify-between items-center">
                            <div class="flex items-center gap-2">
                                <a href="{{ route('management-groups.show', $managementGroup) }}" class="font-medium hover:underline">
                                    {{ $managementGroup->description ?: __('Verwaltungsgruppe') . ' #' . $managementGroup->id }}
                                </a>
                                @if ($managementGroup->isShared())
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4 text-indigo-500" title="{{ __('Geteilt mit mehreren Benutzern') }}">
                                        <path d="M10 9a3 3 0 100-6 3 3 0 000 6zM6 8a2 2 0 11-4 0 2 2 0 014 0zM1.49 15.326a.78.78 0 01-.358-.442 3 3 0 014.308-3.516 6.484 6.484 0 00-1.905 3.959c-.023.222-.014.442.025.654a4.97 4.97 0 01-2.07-.655zM16.44 15.98a4.97 4.97 0 002.07-.654.78.78 0 00.357-.442 3 3 0 00-4.308-3.517 6.484 6.484 0 011.907 3.96 2.32 2.32 0 01-.026.654zM18 8a2 2 0 11-4 0 2 2 0 014 0zM5.304 16.19a.844.844 0 01-.277-.71 5 5 0 019.947 0 .843.843 0 01-.277.71A6.975 6.975 0 0110 18a6.974 6.974 0 01-4.696-1.81z" />
                                    </svg>
                                @endif
                            </div>
                            <span class="text-sm text-gray-500">{{ $managementGroup->management_units_count }} {{ __('Verwaltungseinheiten') }}</span>
                        </li>
                    @empty
                        <li class="px-6 py-4 text-sm text-gray-500">{{ __('Keine Verwaltungsgruppen vorhanden.') }}</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</x-app-layout>
