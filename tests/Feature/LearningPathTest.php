<?php

declare(strict_types=1);

use App\Models\Course;
use App\Models\LearningPath;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(Tenancy::class)->set($this->tenant->id);
    $this->path = LearningPath::factory()->create();
});

it('attaches courses to a path and orders them by pivot position', function () {
    // Regression guard: the pivot table is 'learning_path_course' (not Laravel's
    // alphabetical default 'course_learning_path'), and it carries a composite
    // (learning_path_id, course_id) primary key rather than a surrogate ULID.
    // Both details are needed for attach() to work at all — the relation must
    // name the table, and the pivot must not demand an id it never receives.
    $intro = Course::factory()->create(['title' => 'Intro']);
    $safety = Course::factory()->create(['title' => 'Safety']);

    $this->path->courses()->attach($safety->id, ['position' => 1]);
    $this->path->courses()->attach($intro->id, ['position' => 2]);

    expect($this->path->courses()->count())->toBe(2)
        ->and($this->path->courses()->orderByPivot('position')->first()->title)
        ->toBe('Safety');
});

it('rejects the same course twice in a path via the composite primary key', function () {
    // The (learning_path_id, course_id) primary key — not application logic —
    // guarantees one row per course per path.
    $course = Course::factory()->create();
    $this->path->courses()->attach($course->id, ['position' => 1]);

    expect(fn () => $this->path->courses()->attach($course->id, ['position' => 2]))
        ->toThrow(QueryException::class);
});
