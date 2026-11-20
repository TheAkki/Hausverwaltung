<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * API-Token (Sanctum). Sanctum speichert bei jedem API-Aufruf den Zeitpunkt der
 * letzten Nutzung – bei SQLite ein Schreibvorgang samt Synchronisieren pro Aufruf.
 * Hier wird er nur aktualisiert, wenn der gespeicherte Wert älter als
 * LAST_USED_PRECISION_SECONDS ist; die Tokenliste im Profil zeigt ihn ohnehin nur
 * minutengenau.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    public const LAST_USED_PRECISION_SECONDS = 60;

    /**
     * @return Attribute<Carbon|null, mixed>
     */
    protected function lastUsedAt(): Attribute
    {
        return Attribute::make(
            set: fn ($value, array $attributes) => $this->isRecent($attributes['last_used_at'] ?? null, $value)
                ? $attributes['last_used_at']
                : ($value === null ? null : $this->fromDateTime($value)),
        );
    }

    private function isRecent(mixed $stored, mixed $value): bool
    {
        if ($stored === null || $value === null) {
            return false;
        }

        $storedAt = $this->asDateTime($stored);
        $newAt = $this->asDateTime($value);

        return $newAt->gte($storedAt) && $storedAt->diffInSeconds($newAt) < self::LAST_USED_PRECISION_SECONDS;
    }
}
