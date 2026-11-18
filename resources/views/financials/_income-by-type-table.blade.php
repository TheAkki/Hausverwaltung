@php
    $monthLabels = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
@endphp

<div class="border border-gray-200 rounded-lg overflow-x-auto">
    <table class="w-full text-sm text-left">
        <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
            <tr>
                <th class="px-4 py-3">{{ __('Typ') }}</th>
                @foreach ($monthLabels as $label)
                    <th class="px-2 py-3 text-right whitespace-nowrap">{{ $label }}</th>
                @endforeach
                <th class="px-2 py-3 text-right whitespace-nowrap">{{ __('Summe') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
            @forelse ($income['types'] as $type => $data)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">{{ $type }}</td>
                    @foreach ($data['months'] as $amount)
                        <td class="px-2 py-3 text-right text-gray-600 whitespace-nowrap">{{ $amount > 0 ? euro($amount) . ' €' : '' }}</td>
                    @endforeach
                    <td class="px-2 py-3 text-right font-medium whitespace-nowrap">{{ euro($data['total']) }} €</td>
                </tr>
            @empty
                <tr><td colspan="{{ 2 + count($monthLabels) }}" class="px-4 py-3 text-gray-500">{{ __('Keine aktiven Mietverträge in diesem Jahr.') }}</td></tr>
            @endforelse
        </tbody>
        @if (count($income['types']) > 0)
            <tfoot class="font-semibold border-t border-gray-200 bg-gray-50">
                <tr>
                    <td class="px-4 py-3">{{ __('Summe') }}</td>
                    @foreach ($income['totals'] as $total)
                        <td class="px-2 py-3 text-right whitespace-nowrap">{{ euro($total) }} €</td>
                    @endforeach
                    <td class="px-2 py-3 text-right whitespace-nowrap">{{ euro($income['grandTotal']) }} €</td>
                </tr>
            </tfoot>
        @endif
    </table>
</div>
