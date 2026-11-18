@php
    $views = [
        'tenant_unit' => __('Mieter und Mieteinheit'),
        'tenant' => __('Mieter'),
        'tenant_split' => __('Mieter (Miete + Nebenkosten getrennt)'),
    ];
@endphp

<div class="flex items-center gap-3 text-sm mb-4">
    <span class="text-gray-500">{{ __('Gruppierung') }}:</span>
    @foreach ($views as $value => $label)
        <a
            href="?{{ http_build_query(array_merge(request()->except('income_view'), ['income_view' => $value])) }}"
            class="{{ $incomeView === $value ? 'font-semibold text-gray-900' : 'text-gray-500 hover:underline' }}"
        >{{ $label }}</a>
    @endforeach
</div>
