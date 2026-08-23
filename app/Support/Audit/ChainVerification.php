<?php

declare(strict_types=1);

namespace App\Support\Audit;

/**
 * The outcome of verifying one tenant's audit chain — either intact, or broken at
 * a specific row with a human-readable reason. A small immutable value so the
 * command, tests, and any future dashboard read the same result the same way.
 */
final readonly class ChainVerification
{
    /**
     * @param  string|null  $tenantId  The chain verified (null = platform chain).
     * @param  int  $checked  How many rows were validated before the result.
     * @param  bool  $ok  Whether the chain is intact.
     * @param  int|null  $brokenSequence  The sequence of the first bad row, if any.
     * @param  string|null  $brokenId  The id of the first bad row, if any.
     * @param  string|null  $reason  Why it broke, if it did.
     */
    private function __construct(
        public ?string $tenantId,
        public int $checked,
        public bool $ok,
        public ?int $brokenSequence = null,
        public ?string $brokenId = null,
        public ?string $reason = null,
    ) {}

    public static function intact(?string $tenantId, int $checked): self
    {
        return new self($tenantId, $checked, true);
    }

    public static function broken(?string $tenantId, int $checked, int $brokenSequence, string $brokenId, string $reason): self
    {
        return new self($tenantId, $checked, false, $brokenSequence, $brokenId, $reason);
    }

    /** A readable chain label for output: the tenant id, or "platform". */
    public function label(): string
    {
        return $this->tenantId ?? 'platform';
    }
}
