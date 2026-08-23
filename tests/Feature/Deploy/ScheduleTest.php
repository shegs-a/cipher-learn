<?php

declare(strict_types=1);

/**
 * The operational commands are only useful in production if they are actually
 * scheduled. Pin the wiring (defined in bootstrap/app.php) so a refactor can't
 * quietly drop a job. `schedule:list` forces the schedule to be built in console
 * context and prints each registered command.
 */
it('schedules the operational commands', function () {
    $this->artisan('schedule:list')
        ->assertSuccessful()
        ->expectsOutputToContain('outbox:work')
        ->expectsOutputToContain('hris:sync-employees')
        ->expectsOutputToContain('audit:verify')
        ->expectsOutputToContain('notifications:overdue');
});
