<?php

use App\Http\Middleware\AuthenticateSchool;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('queue:work --stop-when-empty --timeout=900 --tries=3 --max-time=840')
            ->everyMinute()
            ->withoutOverlapping(20);
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'school.token' => AuthenticateSchool::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
