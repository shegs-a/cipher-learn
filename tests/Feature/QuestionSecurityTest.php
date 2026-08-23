<?php

declare(strict_types=1);

use App\Models\Course;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $tenant = Tenant::factory()->create();
    app(Tenancy::class)->set($tenant->id);
    $course = Course::factory()->create();
    $this->quiz = Quiz::factory()->create(['course_id' => $course->id]);
});

it('never exposes correct answers when serialised', function () {
    $question = Question::factory()->create([
        'quiz_id' => $this->quiz->id,
        'correct_keys' => ['a'],
    ]);

    // The whole point: correct_keys must not appear in any array/JSON payload
    // that could reach a learner. Grading stays server-side.
    expect($question->toArray())->not->toHaveKey('correct_keys')
        ->and($question->toJson())->not->toContain('correct_keys');
});

it('still lets server-side code read the correct answer', function () {
    $question = Question::factory()->create([
        'quiz_id' => $this->quiz->id,
        'correct_keys' => ['b'],
    ]);

    // Hidden from serialisation, but readable in-process for grading.
    expect($question->correct_keys)->toBe(['b']);
});
