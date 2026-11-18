<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Benutzergruppen') }}</h2>
            <a href="{{ route('groups.create') }}" class="px-4 py-2 bg-gray-900 text-white rounded-md text-sm font-medium hover:bg-gray-700">
                {{ __('Neue Gruppe') }}
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[922px] mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <ul class="divide-y">
                    @forelse ($groups as $group)
                        <li class="px-6 py-4 flex justify-between items-center">
                            <div>
                                <a href="{{ route('groups.show', $group) }}" class="font-medium hover:underline">{{ $group->name }}</a>
                                <p class="text-sm text-gray-500">{{ $group->description }}</p>
                            </div>
                            <span class="text-sm text-gray-500">{{ $group->users_count }} {{ __('Mitglieder') }}</span>
                        </li>
                    @empty
                        <li class="px-6 py-4 text-sm text-gray-500">{{ __('Keine Gruppen vorhanden.') }}</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</x-app-layout>
