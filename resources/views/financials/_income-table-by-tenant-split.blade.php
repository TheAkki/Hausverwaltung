@php
    $monthLabels = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
@endphp

<div class="border border-gray-200 rounded-lg overflow-x-auto">
    <table class="w-full text-sm text-left">
        <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
            <tr>
                <th class="px-4 py-3">{{ __('Mieter') }}</th>
                <th class="px-4 py-3">{{ __('Art') }}</th>
                @foreach ($monthLabels as $label)
                    <th class="px-2 py-3 text-right whitespace-nowrap">{{ $label }}</th>
                @endforeach
                <th class="px-2 py-3 text-right whitespace-nowrap">{{ __('Summe') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
            @forelse ($income['rows'] as $row)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3" rowspan="2">{{ $row['tenant'] }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ __('Miete') }}</td>
                    @foreach ($row['months'] as $amounts)
                        <td class="px-2 py-3 text-right text-gray-600 whitespace-nowrap">{{ $amounts['rent'] > 0 ? euro($amounts['rent']) . ' €' : '' }}</td>
                    @endforeach
                    <td class="px-2 py-3 text-right font-medium whitespace-nowrap">{{ euro($row['totalRent']) }} €</td>
                </tr>
                <tr class="hover:bg-gray-50 border-b border-gray-200">
                    <td class="px-4 py-3 text-gray-600">{{ __('Nebenkosten') }}</td>
                    @foreach ($row['months'] as $amounts)
                        <td class="px-2 py-3 text-right text-gray-600 whitespace-nowrap">{{ $amounts['utility'] > 0 ? euro($amounts['utility']) . ' €' : '' }}</td>
                    @endforeach
                    <td class="px-2 py-3 text-right font-medium whitespace-nowrap">{{ euro($row['totalUtility']) }} €</td>
                </tr>
            @empty
                <tr><td colspan="{{ 3 + count($monthLabels) }}" class="px-4 py-3 text-gray-500">{{ __('Keine aktiven Mietverträge in diesem Jahr.') }}</td></tr>
            @endforelse
        </tbody>
        @if (count($income['rows']) > 0)
            @php
                // Jahressummen getrennt nach Miete/Nebenkosten – wie bei den Mieter-
                // Zeilen oben zeigt die Summe-Spalte je Zeile deren eigenen Jahreswert,
                // hier über alle Mieter/Mieteinheiten hinweg aufsummiert.
                $summeMiete = array_sum(array_column($income['totals'], 'rent'));
                $summeNebenkosten = array_sum(array_column($income['totals'], 'utility'));
            @endphp
            <tfoot class="font-semibold border-t border-gray-200 bg-gray-50">
                <tr>
                    <td class="px-4 py-3" rowspan="2">{{ __('Summe') }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ __('Miete') }}</td>
                    @foreach ($income['totals'] as $total)
                        <td class="px-2 py-3 text-right whitespace-nowrap">{{ euro($total['rent']) }} €</td>
                    @endforeach
                    <td class="px-2 py-3 text-right whitespace-nowrap">{{ euro($summeMiete) }} €</td>
                </tr>
                <tr>
                    <td class="px-4 py-3 text-gray-600">{{ __('Nebenkosten') }}</td>
                    @foreach ($income['totals'] as $total)
                        <td class="px-2 py-3 text-right whitespace-nowrap">{{ euro($total['utility']) }} €</td>
                    @endforeach
                    <td class="px-2 py-3 text-right whitespace-nowrap">{{ euro($summeNebenkosten) }} €</td>
                </tr>
            </tfoot>
        @endif
    </table>
</div>
