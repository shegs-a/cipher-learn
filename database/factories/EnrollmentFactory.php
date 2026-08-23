<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EnrollmentSource;
use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enrollment>
 */
class EnrollmentFactory extends Factory
{
    protected $model = Enrollment::class;

    public function definition(): array
    {
        // rationale + evidence are always present — the factory reflects the
        // domain rule that no enrolment exists without a stated reason.
        return [
            'source' => EnrollmentSource::Manual,
            'rationale' => 'Assigned manually by L&D for role onboarding.',
            'evidence' => ['assigned_by' => 'seed', 'note' => 'baseline'],
            'method' => 'manual',
            'status' => EnrollmentStatus::Assigned,
            // First enrolment cycle. A later recertification would use a distinct
            // cycle value (e.g. an appraisal period) so the unique key permits it.
            'cycle' => 'initial',
            'due_at' => now()->addDays(30),
        ];
    }

    public function fromRule(string $kpi, float $attainment): static
    {
        return $this->state(fn () => [
            'source' => EnrollmentSource::Rule,
            'method' => 'rule',
            'rationale' => "{$kpi} at ".round($attainment).'% of target — below the 70% threshold.',
            'evidence' => ['kpi' => $kpi, 'attainment' => $attainment],
        ]);
    }
}
