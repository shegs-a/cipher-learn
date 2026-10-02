<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\BindCurrentTenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Runs on every request so every log line carries a correlation id.
        $middleware->append(AssignRequestId::class);

        // Establish the tenant (and, through TenancyTeamResolver, the permission
        // team) from the authenticated user on every web request — so the learner
        // portal and any gated web route are tenant-scoped, exactly as the
        // Filament panel already is. No-ops for guests. This is the "web" half of
        // wiring tenant context into every execution path.
        $middleware->web(append: [BindCurrentTenant::class]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        // The operational heartbeat of the deployed app. Every job is guarded with
        // withoutOverlapping (a slow run never stacks on the next tick) and
        // onOneServer (in a multi-instance deployment exactly one node runs it),
        // both of which rely on the cache lock — Redis in production.

        // Drain the transactional outbox so training-completion write-backs reach
        // the HRIS promptly. A one-shot drain per minute, not a long-lived worker.
        $schedule->command('outbox:work')
            ->everyMinute()
            ->withoutOverlapping()
            ->onOneServer();

        // Pull the workforce from the HRIS each night (self-healing full sync).
        $schedule->command('hris:sync-employees --all')
            ->dailyAt('02:00')
            ->withoutOverlapping()
            ->onOneServer();

        // Leave sync at each tenant's own 06:00 and 18:00. The scheduler fires one
        // wall-clock time in one timezone, so it ticks every 10 minutes and the
        // dispatcher decides per tenant (timezone-aware, idempotent, catches a
        // missed tick) whether a slot is due.
        $schedule->command('hris:dispatch-leave-syncs')
            ->everyTenMinutes()
            ->withoutOverlapping()
            ->onOneServer();

        // Verify each tenant's tamper-evident audit chain nightly; a broken chain
        // makes the command exit non-zero — the signal an alerting layer watches.
        $schedule->command('audit:verify --all')
            ->dailyAt('03:00')
            ->onOneServer();

        // Nudge learners with overdue training once a day (deduped by the command).
        $schedule->command('notifications:overdue')
            ->dailyAt('07:00')
            ->withoutOverlapping()
            ->onOneServer();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
