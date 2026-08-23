<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QuestionType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\QuestionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Question extends Model
{
    /** @use HasFactory<QuestionFactory> */
    use Auditable, BelongsToTenant, HasFactory, HasUlids;

    protected $guarded = [];

    /**
     * `correct_keys` is hidden from array/JSON serialisation so it can never be
     * accidentally leaked into an API response or a rendered payload. Grading is
     * server-side only (Sprint 4). Filament reads attributes directly, so the
     * admin editor is unaffected.
     *
     * @var list<string>
     */
    protected $hidden = ['correct_keys'];

    protected function casts(): array
    {
        return [
            'type' => QuestionType::class,
            'options' => 'array',
            'correct_keys' => 'array',
            'points' => 'integer',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Quiz, $this> */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }
}
