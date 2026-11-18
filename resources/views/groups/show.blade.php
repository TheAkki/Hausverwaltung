<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $group->name }}</h2>
            @if ($canManage)
                <a href="{{ route('groups.edit', $group) }}" class="text-sm text-gray-600 hover:underline">{{ __('Bearbeiten') }}</a>
            @endif
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[922px] mx-auto sm:px-6 lg:px-8 space-y-6">
            @if ($group->description)
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <p class="text-sm text-gray-700">{{ $group->description }}</p>
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold mb-4">{{ __('Mitglieder') }}</h3>

                <table class="w-full text-sm text-left mb-4">
                    <thead class="text-gray-600">
                        <tr>
                            <th class="py-2">{{ __('Name') }}</th>
                            <th class="py-2">{{ __('Email') }}</th>
                            <th class="py-2">{{ __('Rolle') }}</th>
                            @if ($canManage)
                                <th class="py-2"></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @foreach ($group->users as $member)
                            <tr>
                                <td class="py-2">{{ $member->name }}</td>
                                <td class="py-2">{{ $member->email }}</td>
                                <td class="py-2">{{ $member->pivot->role }}</td>
                                @if ($canManage)
                                    <td class="py-2 text-right space-x-2">
                                        <form method="POST" action="{{ route('groups.members.update', [$group, $member]) }}" class="inline">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="role" value="{{ $member->pivot->role === 'admin' ? 'user' : 'admin' }}">
                                            <button type="submit" class="text-gray-600 hover:underline">
                                                {{ $member->pivot->role === 'admin' ? __('Admin entziehen') : __('Zum Admin machen') }}
                                            </button>
                                        </form>
                                        @unless ($member->id === $group->owner_id)
                                            <form method="POST" action="{{ route('groups.members.destroy', [$group, $member]) }}" class="inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-red-600 hover:underline">{{ __('Entfernen') }}</button>
                                            </form>
                                        @endunless
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                @if ($canManage)
                    <form method="POST" action="{{ route('groups.members.store', $group) }}" class="flex items-end gap-4">
                        @csrf
                        <div class="flex-1">
                            <x-input-label for="email" :value="__('Email des Benutzers')" />
                            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" required />
                            <x-input-error :messages="$errors->get('email')" class="mt-2" />
                        </div>
                        <x-primary-button>{{ __('Hinzufügen') }}</x-primary-button>
                    </form>
                @endif
            </div>

            @if ($isOwner)
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <h3 class="font-semibold mb-2 text-red-700">{{ __('Gefahrenzone') }}</h3>
                    <form method="POST" action="{{ route('groups.destroy', $group) }}" onsubmit="return confirm('{{ __('Diese Gruppe wirklich löschen?') }}')">
                        @csrf
                        @method('DELETE')
                        <x-danger-button>{{ __('Gruppe löschen') }}</x-danger-button>
                    </form>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
