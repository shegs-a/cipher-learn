<?php

declare(strict_types=1);

namespace App\Actions\Learning;

use App\Models\Certificate;
use App\Models\Enrollment;
use Illuminate\Support\Carbon;

/**
 * Issues the certificate for a completed course — the one place a `certificates`
 * row is created. Called from {@see CompleteCourse}, so every completion mints
 * exactly one certificate.
 *
 * Idempotent: one certificate per enrolment (the schema enforces
 * `unique(enrollment_id)`), so re-completing or a retried write returns the
 * existing certificate rather than minting a second.
 *
 * The `serial` is a public, globally-unique verification key — it is what the
 * `/verify/{serial}` page resolves without any login — so uniqueness is checked
 * across all tenants (withoutGlobalScopes), not just the current one. `expires_at`
 * is the recertification deadline, derived from the course's `recert_months`
 * (null = never expires).
 *
 * MUST run inside the enrolment's tenant context (the new row is tenant-stamped).
 */
final class IssueCertificate
{
    /** Unambiguous alphabet — no 0/O/1/I/L to keep a spoken/typed serial reliable. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function handle(Enrollment $enrollment): Certificate
    {
        $existing = Certificate::query()
            ->where('enrollment_id', $enrollment->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $enrollment->loadMissing('course');
        $course = $enrollment->course;

        $issuedAt = Carbon::now();
        $expiresAt = $course->recert_months !== null
            ? $issuedAt->copy()->addMonths($course->recert_months)
            : null;

        return Certificate::create([
            'enrollment_id' => $enrollment->id,
            'employee_id' => $enrollment->employee_id,
            'course_id' => $course->id,
            'serial' => $this->uniqueSerial(),
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
        ]);
    }

    /** A readable, collision-checked serial like `SL-4K7Q-9WXM-2PRT`. */
    private function uniqueSerial(): string
    {
        do {
            $serial = 'SL-'.$this->segment().'-'.$this->segment().'-'.$this->segment();
        } while (Certificate::withoutGlobalScopes()->where('serial', $serial)->exists());

        return $serial;
    }

    private function segment(): string
    {
        $out = '';
        for ($i = 0; $i < 4; $i++) {
            $out .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $out;
    }
}
