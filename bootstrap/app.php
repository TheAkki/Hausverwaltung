<?php

use App\Exceptions\GroupMismatchException;
use App\Http\Middleware\ForcePasswordChange;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SetLocale::class,
            ForcePasswordChange::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
        // Stammdaten aus einer fremden Benutzergruppe: in Web und API ein
        // Validierungsfehler am betroffenen Feld (im Seeder bleibt es ein Abbruch).
        $exceptions->map(
            GroupMismatchException::class,
            fn (GroupMismatchException $e) => ValidationException::withMessages([$e->field => $e->getMessage()]),
        );
    })->create();
