<div>
    <x-input-label :value="__('Bekannte CSS-Elemente')" />
    <p class="text-xs text-gray-500 mb-2">
        {{ __('Diese Selektoren werden im Standard-Theme-System verwendet. Grün markierte Elemente deckt dein CSS bereits ab; grau markierte fehlen noch (z. B. weil sie erst nach der Erstellung dieses Themes hinzugekommen sind).') }}
    </p>
    <ul id="selector-checklist" class="text-sm grid grid-cols-2 gap-x-4 gap-y-1 border border-gray-200 rounded-md p-3 max-h-56 overflow-y-auto font-mono">
        @foreach ($knownSelectors as $selector)
            <li data-selector="{{ $selector }}" class="text-gray-400">{{ $selector }}</li>
        @endforeach
    </ul>
</div>

<script>
    (function () {
        const textarea = document.getElementById('css');
        const items = document.querySelectorAll('#selector-checklist li');

        function parseSelectors(css) {
            const found = new Set();
            const ruleRegex = /([^{}]+)\{/g;
            let match;
            while ((match = ruleRegex.exec(css)) !== null) {
                match[1].split(',').forEach(function (selector) {
                    const trimmed = selector.trim();
                    if (trimmed) found.add(trimmed);
                });
            }
            return found;
        }

        function refresh() {
            const found = parseSelectors(textarea.value);
            items.forEach(function (li) {
                const covered = found.has(li.dataset.selector);
                li.classList.toggle('text-green-700', covered);
                li.classList.toggle('font-medium', covered);
                li.classList.toggle('text-gray-400', !covered);
            });
        }

        textarea.addEventListener('input', refresh);
        refresh();
    })();
</script>
