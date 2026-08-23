<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Certificate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Certificate>
 */
class CertificateFactory extends Factory
{
    protected $model = Certificate::class;

    public function definition(): array
    {
        return [
            'serial' => 'SL-'.strtoupper(Str::random(10)),
            'issued_at' => now(),
            'expires_at' => null,
        ];
    }
}
