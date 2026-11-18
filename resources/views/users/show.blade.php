<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $user->name }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[691px] mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <dl class="space-y-2 text-sm">
                    <div>
                        <dt class="text-gray-500">{{ __('Name') }}</dt>
                        <dd class="text-gray-900">{{ $user->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('Email') }}</dt>
                        <dd class="text-gray-900">{{ $user->email }}</dd>
                    </div>
                </dl>
                <div class="mt-4">
                    <a href="{{ route('users.edit', $user) }}" class="text-sm text-gray-600 hover:underline">{{ __('Bearbeiten') }}</a>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold mb-3">{{ __('Benutzergruppen') }}</h3>
                <ul class="text-sm divide-y">
                    @foreach ($user->groups as $group)
                        <li class="py-2 flex justify-between">
                            <a href="{{ route('groups.show', $group) }}" class="hover:underline">{{ $group->name }}</a>
                            <span class="text-gray-500">{{ $group->pivot->role }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</x-app-layout>
