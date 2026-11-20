<?php

// KI-generiert

namespace Tests\Unit;

use App\Support\SqlitePragmas;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class SqlitePragmasTest extends TestCase
{
    public function test_defaults_are_wal_normal_and_a_busy_timeout(): void
    {
        $this->assertSame('wal', SqlitePragmas::journalMode(null));
        $this->assertSame('wal', SqlitePragmas::journalMode('  '));
        $this->assertSame('normal', SqlitePragmas::synchronous(null));
        $this->assertSame(SqlitePragmas::DEFAULT_BUSY_TIMEOUT, SqlitePragmas::busyTimeout(null));
        $this->assertSame(SqlitePragmas::DEFAULT_BUSY_TIMEOUT, SqlitePragmas::busyTimeout(''));
    }

    public function test_valid_values_are_normalized(): void
    {
        $this->assertSame('delete', SqlitePragmas::journalMode(' DELETE '));
        $this->assertSame('full', SqlitePragmas::synchronous('Full'));
        $this->assertSame(0, SqlitePragmas::busyTimeout('0'));
        $this->assertSame(250, SqlitePragmas::busyTimeout(250));
    }

    public function test_an_unknown_journal_mode_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('DB_JOURNAL_MODE');
        SqlitePragmas::journalMode('wal; drop table users');
    }

    public function test_an_unknown_synchronous_mode_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('DB_SYNCHRONOUS');
        SqlitePragmas::synchronous('schnell');
    }

    public function test_a_busy_timeout_that_is_no_whole_number_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('DB_BUSY_TIMEOUT');
        SqlitePragmas::busyTimeout('-5');
    }

    public function test_a_file_database_is_opened_in_wal_mode_with_normal_sync(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'hv-sqlite-');
        config(['database.connections.pragma_test' => [...config('database.connections.sqlite'), 'database' => $file]]);

        try {
            $connection = DB::connection('pragma_test');

            $this->assertSame('wal', $connection->selectOne('PRAGMA journal_mode')->journal_mode);
            $this->assertSame(1, (int) $connection->selectOne('PRAGMA synchronous')->synchronous);
            $this->assertSame(SqlitePragmas::DEFAULT_BUSY_TIMEOUT, (int) $connection->selectOne('PRAGMA busy_timeout')->timeout);
        } finally {
            DB::purge('pragma_test');
            foreach ([$file, "{$file}-wal", "{$file}-shm"] as $path) {
                @unlink($path);
            }
        }
    }
}
