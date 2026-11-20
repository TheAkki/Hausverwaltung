<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Jede ändernde Anfrage (POST, PUT, PATCH, DELETE) läuft in einer Datenbanktransaktion:
 * ein Festschreiben pro Anfrage statt einzeln je SQL-Anweisung (bei SQLite je ein fsync)
 * und keine halb gespeicherten Daten, wenn die Anfrage scheitert.
 *
 * Zurückgenommen wird bei jeder Fehlerantwort (Status ab 400) und bei jeder Antwort, die
 * aus einer Ausnahme entstanden ist – z. B. ein Validierungsfehler, der als Weiterleitung
 * zurück zum Formular endet. Liegt hinter StartSession, damit die Sitzung (mit den
 * Fehlermeldungen) danach trotzdem gespeichert wird. Die Anmeldezähler liegen deshalb
 * nicht in der Datenbank (config/cache.php "limiter").
 */
class TransactionPerRequest
{
    private const FIRST_ERROR_STATUS = 400;

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $connection = DB::connection();
        $level = $connection->transactionLevel();
        $connection->beginTransaction();

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->finish($connection, $level, commit: false);

            throw $exception;
        }

        $this->finish($connection, $level, commit: ! $this->failed($response));

        return $response;
    }

    private function failed(Response $response): bool
    {
        return $response->getStatusCode() >= self::FIRST_ERROR_STATUS
            || (property_exists($response, 'exception') && $response->exception !== null);
    }

    /**
     * Schließt nur die eigene Transaktion – falls der Code darunter sie schon selbst
     * beendet hat, bleibt nichts zu tun.
     */
    private function finish(Connection $connection, int $level, bool $commit): void
    {
        if ($connection->transactionLevel() <= $level) {
            return;
        }

        $commit ? $connection->commit() : $connection->rollBack($level);
    }
}
