@php
    $monthLabels = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
@endphp

<div class="border border-gray-200 rounded-lg overflow-x-auto">
    <table class="w-full text-sm text-left">
        <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
            <tr>
                <th class="px-4 py-3">{{ __('Mieteinheit') }}</th>
                @foreach ($monthLabels as $label)
                    <th class="px-2 py-3 text-center">{{ $label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
            @forelse ($occupancy as $row)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-600">{{ $row['rental_unit'] }}</td>
                    @foreach ($row['months'] as $tenantNames)
                        <td class="px-2 py-3 text-center">
                            @if ($tenantNames)
                                <span class="inline-block w-2.5 h-2.5 rounded-full bg-green-500" title="{{ $tenantNames }}"></span>
                            @else
                                <span class="inline-block w-2.5 h-2.5 rounded-full bg-gray-200" title="{{ __('Frei') }}"></span>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ 1 + count($monthLabels) }}" class="px-4 py-3 text-gray-500">{{ __('Keine Mieteinheiten vorhanden.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
