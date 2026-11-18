/**
 * Tabulatoren in Textfeldern (z. B. den Template-Feldern) zulassen: Tab fügt einen
 * Tabulator ein bzw. rückt alle markierten Zeilen ein, Umschalt+Tab rückt aus.
 * Esc gibt die Tab-Taste einmal frei, damit sich das Feld per Tastatur verlassen lässt.
 * Aktiv für jedes <textarea data-tab-indent>.
 */

export const TAB = '\t';
export const SPACES_PER_TAB = 4;
export const SELECTOR = 'textarea[data-tab-indent]';

const NEWLINE = '\n';

// Reine Modifier-Tasten heben die Freigabe durch Esc nicht auf (Esc, Umschalt+Tab).
const MODIFIER_KEYS = ['Shift', 'Control', 'Alt', 'Meta'];

/** Beginn der Zeile, in der `position` liegt. */
function lineStart(value, position) {
    return value.lastIndexOf(NEWLINE, position - 1) + 1;
}

/** Entfernt eine Einrückungsebene (Tabulator oder bis zu SPACES_PER_TAB Leerzeichen). */
function outdentLine(line) {
    if (line.startsWith(TAB)) {
        return line.slice(TAB.length);
    }

    const spaces = line.match(/^ */)[0].length;

    return line.slice(Math.min(spaces, SPACES_PER_TAB));
}

/**
 * Berechnet die Änderung für einen Tab-Tastendruck, ohne das Feld anzufassen.
 *
 * @returns {{from: number, to: number, text: string, selectionStart: number, selectionEnd: number}}
 *          Ersetze value[from, to) durch text und setze danach die Auswahl.
 */
export function tabEdit(value, start, end, outdent) {
    const multiline = value.slice(start, end).includes(NEWLINE);

    if (!outdent && !multiline) {
        const caret = start + TAB.length;

        return { from: start, to: end, text: TAB, selectionStart: caret, selectionEnd: caret };
    }

    const from = lineStart(value, start);
    // Endet die Auswahl direkt am Zeilenanfang, gehört diese Zeile nicht mehr dazu.
    const to = end > start && value[end - 1] === NEWLINE ? end - 1 : end;
    const blockEnd = value.indexOf(NEWLINE, to) === -1 ? value.length : value.indexOf(NEWLINE, to);
    const lines = value.slice(from, blockEnd).split(NEWLINE);
    const changed = lines.map((line) => (outdent ? outdentLine(line) : TAB + line));
    const text = changed.join(NEWLINE);

    const firstShift = changed[0].length - lines[0].length;
    const selectionStart = Math.max(from, start + firstShift);
    const selectionEnd = start === end ? selectionStart : end + (text.length - (blockEnd - from));

    return { from, to: blockEnd, text, selectionStart, selectionEnd };
}

/** Wendet die Änderung an – über insertText, damit Strg+Z sie rückgängig machen kann. */
function apply(textarea, edit) {
    textarea.setSelectionRange(edit.from, edit.to);

    const viaUndoStack = typeof document.execCommand === 'function' && document.execCommand('insertText', false, edit.text);
    if (!viaUndoStack) {
        textarea.setRangeText(edit.text, edit.from, edit.to);
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    }

    textarea.setSelectionRange(edit.selectionStart, edit.selectionEnd);
}

export function enableTabIndent(textarea) {
    let released = false;

    textarea.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            released = true;
            return;
        }

        if (MODIFIER_KEYS.includes(event.key)) {
            return;
        }

        const plainTab = event.key === 'Tab' && !event.ctrlKey && !event.altKey && !event.metaKey;
        if (!plainTab || released) {
            released = false;
            return;
        }

        event.preventDefault();
        apply(textarea, tabEdit(textarea.value, textarea.selectionStart, textarea.selectionEnd, event.shiftKey));
    });

    textarea.addEventListener('blur', () => {
        released = false;
    });
}

export function enableAllTabIndents(root = document) {
    root.querySelectorAll(SELECTOR).forEach(enableTabIndent);
}
