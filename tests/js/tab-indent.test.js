// KI-generiert
// Ausführen: npm test (Node-eigener Testrunner, keine zusätzliche Abhängigkeit).

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { enableAllTabIndents, enableTabIndent, SELECTOR, tabEdit } from '../../resources/js/tab-indent.js';

/** Wendet tabEdit auf einen Text an; "|" markiert Cursor bzw. Auswahlgrenzen. */
function press(marked, outdent = false) {
    const start = marked.indexOf('|');
    const end = marked.lastIndexOf('|') - (marked.indexOf('|') === marked.lastIndexOf('|') ? 0 : 1);
    const value = marked.replaceAll('|', '');
    const edit = tabEdit(value, start, end, outdent);
    const result = value.slice(0, edit.from) + edit.text + value.slice(edit.to);

    const markers = edit.selectionStart === edit.selectionEnd ? [edit.selectionStart] : [edit.selectionStart, edit.selectionEnd];

    return markers.reduceRight((text, at) => text.slice(0, at) + '|' + text.slice(at), result);
}

// KI-generiert
test('Tab fügt am Cursor einen Tabulator ein', () => {
    assert.equal(press('<p>|x</p>'), '<p>\t|x</p>');
});

// KI-generiert
test('Tab ersetzt eine Auswahl innerhalb einer Zeile', () => {
    assert.equal(press('a|bc|d'), 'a\t|d');
});

// KI-generiert
test('Tab rückt alle markierten Zeilen ein und hält die Auswahl', () => {
    assert.equal(press('a|b\ncd\ne|f'), '\ta|b\n\tcd\n\te|f');
});

// KI-generiert
test('eine Auswahl bis zum Anfang der Folgezeile schließt diese nicht ein', () => {
    assert.equal(press('|ab\n|cd'), '\t|ab\n|cd');
});

// KI-generiert
test('Umschalt+Tab entfernt einen Tabulator der aktuellen Zeile', () => {
    assert.equal(press('x\n\tab|c', true), 'x\nab|c');
});

// KI-generiert
test('Umschalt+Tab entfernt bis zu vier Leerzeichen', () => {
    assert.equal(press('      a|b', true), '  a|b');
    assert.equal(press('  a|b', true), 'a|b');
});

// KI-generiert
test('Umschalt+Tab ohne Einrückung ändert nichts', () => {
    assert.equal(press('a|b', true), 'a|b');
});

// KI-generiert
test('Umschalt+Tab mit Cursor in der Einrückung bleibt am Zeilenanfang', () => {
    assert.equal(press('\t|ab', true), '|ab');
});

// KI-generiert
test('Umschalt+Tab rückt mehrere Zeilen aus', () => {
    assert.equal(press('\t|a\n    b\n\tc|', true), '|a\nb\nc|');
});

/** Minimales Textfeld für die Tastatur-Logik. */
class FakeTextarea {
    constructor(value = '') {
        this.value = value;
        this.selectionStart = 0;
        this.selectionEnd = 0;
        this.listeners = {};
        this.inputEvents = 0;
    }

    addEventListener(type, listener) {
        this.listeners[type] = listener;
    }

    setSelectionRange(start, end) {
        this.selectionStart = start;
        this.selectionEnd = end;
    }

    setRangeText(text, from, to) {
        this.value = this.value.slice(0, from) + text + this.value.slice(to);
    }

    dispatchEvent(event) {
        if (event.type === 'input') {
            this.inputEvents++;
        }
    }

    key(key, modifiers = {}) {
        const event = { key, ...modifiers, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
        this.listeners.keydown(event);

        return event;
    }
}

function withDocument(execCommand, run) {
    const previous = globalThis.document;
    globalThis.document = execCommand === undefined ? {} : { execCommand };
    try {
        run();
    } finally {
        globalThis.document = previous;
    }
}

// KI-generiert
test('Tab im Feld wird abgefangen und eingefügt (Fallback ohne execCommand)', () => {
    withDocument(undefined, () => {
        const textarea = new FakeTextarea('ab');
        enableTabIndent(textarea);
        textarea.setSelectionRange(1, 1);

        assert.equal(textarea.key('Tab').defaultPrevented, true);
        assert.equal(textarea.value, 'a\tb');
        assert.equal(textarea.selectionStart, 2);
        assert.equal(textarea.inputEvents, 1);
    });
});

// KI-generiert
test('insertText wird bevorzugt, damit Rückgängig funktioniert', () => {
    const calls = [];
    withDocument((command, _ui, text) => {
        calls.push([command, text]);
        return true;
    }, () => {
        const textarea = new FakeTextarea('ab');
        enableTabIndent(textarea);
        textarea.setSelectionRange(0, 0);
        textarea.key('Tab');

        assert.deepEqual(calls, [['insertText', '\t']]);
        assert.equal(textarea.inputEvents, 0);
    });
});

// KI-generiert
test('schlägt insertText fehl, greift der Fallback', () => {
    withDocument(() => false, () => {
        const textarea = new FakeTextarea('ab');
        enableTabIndent(textarea);
        textarea.key('Tab');

        assert.equal(textarea.value, '\tab');
    });
});

// KI-generiert
test('Umschalt+Tab rückt im Feld aus', () => {
    withDocument(undefined, () => {
        const textarea = new FakeTextarea('\tab');
        enableTabIndent(textarea);
        textarea.setSelectionRange(2, 2);
        textarea.key('Tab', { shiftKey: true });

        assert.equal(textarea.value, 'ab');
    });
});

// KI-generiert
test('Esc gibt die nächste Tab-Taste frei, auch mit Umschalt', () => {
    withDocument(undefined, () => {
        const textarea = new FakeTextarea('ab');
        enableTabIndent(textarea);

        textarea.key('Escape');
        textarea.key('Shift');
        assert.equal(textarea.key('Tab', { shiftKey: true }).defaultPrevented, false);
        assert.equal(textarea.value, 'ab');

        assert.equal(textarea.key('Tab').defaultPrevented, true, 'die Freigabe gilt nur einmal');
    });
});

// KI-generiert
test('eine andere Taste oder das Verlassen hebt die Freigabe auf', () => {
    withDocument(undefined, () => {
        const textarea = new FakeTextarea('');
        enableTabIndent(textarea);

        textarea.key('Escape');
        textarea.key('a');
        assert.equal(textarea.key('Tab').defaultPrevented, true);

        textarea.key('Escape');
        textarea.listeners.blur();
        assert.equal(textarea.key('Tab').defaultPrevented, true);
    });
});

// KI-generiert
test('Tab mit Strg, Alt oder Meta bleibt dem Browser überlassen', () => {
    withDocument(undefined, () => {
        const textarea = new FakeTextarea('');
        enableTabIndent(textarea);

        for (const modifier of ['ctrlKey', 'altKey', 'metaKey']) {
            assert.equal(textarea.key('Tab', { [modifier]: true }).defaultPrevented, false, modifier);
        }
        assert.equal(textarea.value, '');
    });
});

// KI-generiert
test('enableAllTabIndents aktiviert alle markierten Felder', () => {
    const fields = [new FakeTextarea(), new FakeTextarea()];
    const root = {
        querySelectorAll(selector) {
            assert.equal(selector, SELECTOR);
            return fields;
        },
    };

    enableAllTabIndents(root);

    assert.ok(fields.every((field) => typeof field.listeners.keydown === 'function'));
});
