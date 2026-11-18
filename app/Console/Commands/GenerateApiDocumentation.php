<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Console command that shells out to doxygen to (re)build the source-code reference.
 */
#[Signature('docs:api')]
#[Description('Generates the Doxygen source-code reference from the Doxyfile at the project root')]
class GenerateApiDocumentation extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! shell_exec('command -v doxygen')) {
            $this->error('doxygen ist nicht installiert. Siehe https://www.doxygen.nl/download.html');

            return self::FAILURE;
        }

        $result = Process::path(base_path())->timeout(300)->run('doxygen Doxyfile', function (string $_type, string $line): void {
            $this->output->write($line);
        });

        if (! $result->successful()) {
            return self::FAILURE;
        }

        $this->info('Quellcode-Dokumentation generiert: '.public_path('docs/api/html/index.html'));

        return self::SUCCESS;
    }
}
