<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Hris\ProcessOutbox;
use Illuminate\Console\Command;

/**
 * Drain the transactional outbox, pushing completed training back to each
 * tenant's HR system.
 *
 * The operational entry point to the write-back: run on a schedule in production
 * and by hand in a demo. Deliberately safe to run repeatedly — it only touches
 * events that are due, and each outcome (processed / unsupported / failed /
 * retried) is terminal or explicitly re-scheduled, never reprocessed by accident.
 */
class ProcessOutboxCommand extends Command
{
    protected $signature = 'outbox:work';

    protected $description = 'Process due training-completion write-backs through each tenant\'s HRIS adapter';

    public function handle(ProcessOutbox $processOutbox): int
    {
        $stats = $processOutbox->handle();

        $this->components->info(sprintf(
            'Outbox drained: %d processed, %d unsupported, %d retried, %d failed.',
            $stats['processed'],
            $stats['unsupported'],
            $stats['retried'],
            $stats['failed'],
        ));

        // A `failed` event is a genuine problem (a supported write-back that kept
        // erroring); surface it as a non-zero exit so a scheduler/alert notices.
        // `unsupported` is expected (ExampleHR today) and stays a success.
        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
