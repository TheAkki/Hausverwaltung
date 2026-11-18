@php
    $monthLabels = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
    $tableId = \Illuminate\Support\Str::random(8);
@endphp

<div id="expense-table-{{ $tableId }}" class="border border-gray-200 rounded-lg overflow-x-auto">
    <table class="w-full text-sm text-left">
        <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
            <tr>
                <th class="px-4 py-3">{{ __('Kategorie') }}</th>
                @foreach ($monthLabels as $label)
                    <th class="px-2 py-3 text-right whitespace-nowrap">{{ $label }}</th>
                @endforeach
                <th class="px-2 py-3 text-right whitespace-nowrap">{{ __('Summe') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
            @forelse ($expenses['categories'] as $index => $category)
                @php($detailClass = "expense-detail-{$tableId}-{$index}")
                <tr class="hover:bg-gray-50 cursor-pointer" data-expense-toggle data-target-class="{{ $detailClass }}">
                    <td class="px-4 py-3">{{ $category['name'] }}</td>
                    @foreach ($category['months'] as $amount)
                        <td class="px-2 py-3 text-right text-gray-600 whitespace-nowrap">{{ $amount > 0 ? euro($amount) . ' €' : '' }}</td>
                    @endforeach
                    <td class="px-2 py-3 text-right font-medium whitespace-nowrap">{{ euro($category['total']) }} €</td>
                </tr>
                @foreach ($category['invoices'] as $invoiceRow)
                    <tr class="hidden {{ $detailClass }} bg-gray-50">
                        <td class="pl-8 pr-4 py-2 text-xs text-gray-500 text-right">{{ $invoiceRow['label'] }}</td>
                        @foreach ($invoiceRow['months'] as $amount)
                            <td class="px-2 py-2 text-right text-xs text-gray-500 whitespace-nowrap">{{ $amount > 0 ? euro($amount) . ' €' : '' }}</td>
                        @endforeach
                        <td class="px-2 py-2 text-right text-xs text-gray-500 whitespace-nowrap">{{ euro($invoiceRow['total']) }} €</td>
                    </tr>
                @endforeach
            @empty
                <tr><td colspan="{{ 2 + count($monthLabels) }}" class="px-4 py-3 text-gray-500">{{ __('Keine Ausgaben in diesem Jahr.') }}</td></tr>
            @endforelse
        </tbody>
        @if (count($expenses['categories']) > 0)
            <tfoot class="font-semibold border-t border-gray-200 bg-gray-50">
                <tr>
                    <td class="px-4 py-3">{{ __('Summe') }}</td>
                    @foreach ($expenses['totals'] as $total)
                        <td class="px-2 py-3 text-right whitespace-nowrap">{{ euro($total) }} €</td>
                    @endforeach
                    <td class="px-2 py-3 text-right whitespace-nowrap">{{ euro($expenses['grandTotal']) }} €</td>
                </tr>
            </tfoot>
        @endif
    </table>
</div>

<script>
    // Scoped to this table's own container: the page can (and does) include this partial
    // more than once (Prognostizierte/Gebuchte Ausgaben) – querying the whole document
    // here would, by the time the second table's script runs, also re-match the first
    // table's rows (already present in the DOM) and bind a second, canceling-out click
    // listener to them, so a click on that first table's rows visibly did nothing.
    document.querySelectorAll('#expense-table-{{ $tableId }} [data-expense-toggle]').forEach(function (row) {
        row.addEventListener('click', function () {
            document.querySelectorAll('.' + row.dataset.targetClass).forEach(function (el) {
                el.classList.toggle('hidden');
            });
        });
    });
</script>
