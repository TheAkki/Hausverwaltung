{{--
    "Übersicht aller Buchungen" (Konzept.md "### Betriebskostenabrechnung") for one
    Abrechnungsintervall. $bookings = FinancialOverviewService::bookingsByCategory()'s
    return value for that interval, shared by management-groups/show.blade.php and
    management-units/show.blade.php.
--}}
<div class="mt-2 pl-4 space-y-3">
    @forelse ($bookings['categories'] as $category)
        <div>
            <div class="flex justify-between font-medium">
                <span>{{ $category['name'] }}</span>
                <span>{{ number_format($category['total'], 2, ',', '.') }} €</span>
            </div>
            <ul class="pl-4 text-gray-600">
                @foreach ($category['rows'] as $row)
                    <li class="flex justify-between">
                        <span>
                            {{ \Carbon\Carbon::parse($row['date'])->format('d.m.Y') }}
                            &ndash; {{ $row['label'] }}
                            @if ($row['description'])
                                <span class="text-gray-500">({{ $row['description'] }})</span>
                            @endif
                            @if ($row['open'])
                                <span class="text-xs text-gray-400">({{ __('offen') }})</span>
                            @endif
                        </span>
                        <span>{{ number_format($row['amount'], 2, ',', '.') }} €</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @empty
        <p class="text-gray-500">{{ __('Keine Buchungen in diesem Abrechnungsintervall.') }}</p>
    @endforelse

    @if (! empty($bookings['categories']))
        <div class="flex justify-between font-semibold border-t pt-2">
            <span>{{ __('Gesamtsumme') }}</span>
            <span>{{ number_format($bookings['grandTotal'], 2, ',', '.') }} €</span>
        </div>
    @endif
</div>
