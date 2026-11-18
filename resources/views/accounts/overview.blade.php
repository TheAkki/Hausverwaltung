<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Kontoübersicht') }}: {{ $managementGroup->description ?: __('Verwaltungsgruppe') . ' #' . $managementGroup->id }}
        </h2>
    </x-slot>

    @php
        $monthLabels = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    @endphp

    <div class="py-12">
        <div class="max-w-[1229px] mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <div class="flex items-center gap-3 text-sm mb-4">
                    <a href="?{{ http_build_query(['year' => $year - 1]) }}" class="text-gray-500 hover:underline">&laquo; {{ $year - 1 }}</a>
                    <span class="font-semibold">{{ $year }}</span>
                    <a href="?{{ http_build_query(['year' => $year + 1]) }}" class="text-gray-500 hover:underline">{{ $year + 1 }} &raquo;</a>
                </div>

                <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                        <tr>
                            <th class="px-4 py-3"></th>
                            @foreach ($balances['rows'] as $row)
                                <th class="px-2 py-3 text-right whitespace-nowrap">{{ $row['account']->name }}</th>
                            @endforeach
                            <th class="px-2 py-3 text-right whitespace-nowrap">{{ __('Summe') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <tr class="font-semibold bg-gray-50">
                            <td class="px-4 py-2">{{ __('Saldo Jahresanfang') }}</td>
                            @foreach ($balances['rows'] as $row)
                                <td class="px-2 py-2 text-right whitespace-nowrap">{{ euro($row['opening']) }} €</td>
                            @endforeach
                            <td class="px-2 py-2 text-right whitespace-nowrap">{{ euro($balances['openingTotal']) }} €</td>
                        </tr>
                        @foreach ($monthLabels as $index => $label)
                            @php($month = $index + 1)
                            <tr>
                                <td class="px-4 py-2">{{ $label }}</td>
                                @foreach ($balances['rows'] as $row)
                                    <td class="px-2 py-2 text-right whitespace-nowrap {{ $row['months'][$month] < 0 ? 'text-red-600' : 'text-green-700' }}">
                                        {{ $row['months'][$month] >= 0 ? '+' : '' }}{{ euro($row['months'][$month]) }} €
                                    </td>
                                @endforeach
                                <td class="px-2 py-2 text-right whitespace-nowrap {{ $balances['monthTotals'][$month] < 0 ? 'text-red-600' : 'text-green-700' }}">
                                    {{ $balances['monthTotals'][$month] >= 0 ? '+' : '' }}{{ euro($balances['monthTotals'][$month]) }} €
                                </td>
                            </tr>
                        @endforeach
                        <tr class="font-semibold bg-gray-50 border-t-2 border-gray-300">
                            <td class="px-4 py-2">{{ __('Saldo Jahresende / Ist-Saldo') }}</td>
                            @foreach ($balances['rows'] as $row)
                                <td class="px-2 py-2 text-right whitespace-nowrap">{{ euro($row['closing']) }} €</td>
                            @endforeach
                            <td class="px-2 py-2 text-right whitespace-nowrap">{{ euro($balances['closingTotal']) }} €</td>
                        </tr>
                    </tbody>
                </table>
                </div>

                @if (count($balances['rows']) === 0)
                    <p class="text-sm text-gray-500 mt-4">{{ __('Noch keine Konten angelegt.') }}</p>
                @endif

                <a href="{{ route('management-groups.show', $managementGroup) }}" class="text-sm text-gray-600 hover:underline mt-4 inline-block">{{ __('Zurück zur Verwaltungsgruppe') }}</a>
            </div>
        </div>
    </div>
</x-app-layout>
