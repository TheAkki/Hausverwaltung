@php
    // Marks the link as coming from the Belegungsmatrix's/Belegungsplan's own year
    // switcher, so the controller can tell it apart from the unrelated Mieteinnahmen/
    // Ausgaben switcher on the same page and only auto-expand that one <details> block
    // when its own year actually changed.
    $matrixParam = ($matrixSwitcher ?? false) ? ['matrix' => 1] : [];
    $planParam = ($planSwitcher ?? false) ? ['plan' => 1] : [];
    $baseParams = request()->except(['year', 'matrix', 'plan']);

    // If given, points the link back at the anchor of the <details> block this year
    // switcher belongs to, so following it scrolls straight back to that block instead
    // of landing at the top of the page – it shouldn't look like the whole page reloaded.
    $anchorSuffix = isset($anchor) ? '#'.$anchor : '';
@endphp
<div class="flex items-center gap-3 text-sm mb-4">
    <a href="?{{ http_build_query(array_merge($baseParams, ['year' => $year - 1], $matrixParam, $planParam)) }}{{ $anchorSuffix }}" class="text-gray-500 hover:underline">&laquo; {{ $year - 1 }}</a>
    <span class="font-semibold">{{ $year }}</span>
    <a href="?{{ http_build_query(array_merge($baseParams, ['year' => $year + 1], $matrixParam, $planParam)) }}{{ $anchorSuffix }}" class="text-gray-500 hover:underline">{{ $year + 1 }} &raquo;</a>
</div>
