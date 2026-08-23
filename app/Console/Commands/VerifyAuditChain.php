<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Audit\ChainVerifier;
use Illuminate\Console\Command;

/**
 * Re-walks the tamper-evident audit chain(s) and reports whether history is
 * intact — the operational face of the audit trail's guarantee.
 *
 * Run it on a schedule and/or before trusting an export. A non-zero exit means at
 * least one chain failed verification, naming the tenant, the row, and why — an
 * alarm that some row was edited, deleted, inserted, or reordered outside the
 * append-only path (e.g. a direct SQL write).
 *
 *   php artisan audit:verify --all           # every chain
 *   php artisan audit:verify --tenant=01J...  # one tenant
 *   php artisan audit:verify                  # the platform (null-tenant) chain
 */
final class VerifyAuditChain extends Command
{
    protected $signature = 'audit:verify {--tenant= : Verify a single tenant\'s chain by id} {--all : Verify every chain}';

    protected $description = 'Verify the integrity of the tamper-evident audit chain(s)';

    public function handle(ChainVerifier $verifier): int
    {
        // Decide which chains to check: --all sweeps everything present; --tenant
        // targets one; the bare command checks the platform (null-tenant) chain.
        if ($this->option('all')) {
            $chains = $verifier->chains();

            if ($chains === []) {
                $this->info('No audit entries yet — nothing to verify.');

                return self::SUCCESS;
            }
        } elseif ($this->option('tenant') !== null) {
            $chains = [(string) $this->option('tenant')];
        } else {
            $chains = [null];
        }

        $allIntact = true;

        foreach ($chains as $tenantId) {
            $result = $verifier->verify($tenantId);

            if ($result->ok) {
                $this->info(sprintf('✓ %s — intact (%d %s verified)', $result->label(), $result->checked, str('row')->plural($result->checked)));

                continue;
            }

            $allIntact = false;
            $this->error(sprintf(
                '✗ %s — BROKEN at sequence %d (row %s): %s',
                $result->label(),
                $result->brokenSequence,
                $result->brokenId,
                $result->reason,
            ));
        }

        if (! $allIntact) {
            $this->newLine();
            $this->error('Audit verification FAILED — the trail has been altered outside the append-only path.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
