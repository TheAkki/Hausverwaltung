<?php

// KI-generiert

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Die Migrationen laufen auch unter MySQL/MariaDB und PostgreSQL (Docker,
 * DB_CONNECTION). Dort sind Namen von Indizes und Fremdschlüsseln begrenzt;
 * SQLite (Tests) kennt keine Grenze und würde einen zu langen Namen sonst nie
 * bemerken.
 */
class DatabaseIdentifierLengthTest extends TestCase
{
    use RefreshDatabase;

    /** PostgreSQL: 63 Zeichen, MySQL/MariaDB: 64 – es gilt die kleinere Grenze. */
    private const MAX_IDENTIFIER_LENGTH = 63;

    /** Laravels Namensschema für Fremdschlüssel: {tabelle}_{spalten}_foreign. */
    private const FOREIGN_KEY_SUFFIX = '_foreign';

    // KI-generiert
    public function test_no_index_name_exceeds_the_limit_of_mysql_and_postgresql(): void
    {
        $this->assertSame([], $this->tooLong($this->indexNames()));
    }

    // KI-generiert
    public function test_no_foreign_key_name_exceeds_the_limit_of_mysql_and_postgresql(): void
    {
        $this->assertSame([], $this->tooLong($this->foreignKeyNames()));
    }

    // KI-generiert
    public function test_the_check_itself_reports_a_name_above_the_limit(): void
    {
        $limit = str_repeat('a', self::MAX_IDENTIFIER_LENGTH);

        $this->assertSame([$limit.'b'], $this->tooLong([$limit, $limit.'b']));
    }

    /**
     * @return list<string>
     */
    private function indexNames(): array
    {
        $names = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                if (! $index['primary']) {
                    $names[] = $index['name'];
                }
            }
        }

        return $names;
    }

    /**
     * Namen, die Laravel unter MySQL/PostgreSQL für die Fremdschlüssel vergäbe
     * (SQLite speichert sie nicht).
     *
     * @return list<string>
     */
    private function foreignKeyNames(): array
    {
        $names = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                $names[] = $foreignKey['name'] ?: $table.'_'.implode('_', $foreignKey['columns']).self::FOREIGN_KEY_SUFFIX;
            }
        }

        return $names;
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function tooLong(array $names): array
    {
        return array_values(array_filter($names, fn (string $name) => strlen($name) > self::MAX_IDENTIFIER_LENGTH));
    }
}
