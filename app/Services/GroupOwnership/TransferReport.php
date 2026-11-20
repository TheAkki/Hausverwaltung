<?php

namespace App\Services\GroupOwnership;

/**
 * Was beim Verschieben einer Verwaltungsgruppe mit ihren Stammdaten passiert ist.
 */
final class TransferReport
{
    /** @var list<string> */
    public array $moved = [];

    /** @var list<string> */
    public array $copied = [];

    /** @var list<string> */
    public array $reused = [];

    public function isEmpty(): bool
    {
        return $this->moved === [] && $this->copied === [] && $this->reused === [];
    }

    /**
     * Eine Zeile je Art, für die Meldung in der Weboberfläche.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return array_values(array_filter([
            $this->line(__('Mit verschoben'), $this->moved),
            $this->line(__('In die neue Gruppe kopiert (wird auch anderswo verwendet oder ist eine Vorlage)'), $this->copied),
            $this->line(__('Gleichen Eintrag der neuen Gruppe verwendet'), $this->reused),
        ]));
    }

    /**
     * @return array{moved: list<string>, copied: list<string>, reused: list<string>}
     */
    public function toArray(): array
    {
        return ['moved' => $this->moved, 'copied' => $this->copied, 'reused' => $this->reused];
    }

    /**
     * @param  list<string>  $labels
     */
    private function line(string $title, array $labels): ?string
    {
        return $labels === [] ? null : $title.': '.implode(', ', $labels);
    }
}
