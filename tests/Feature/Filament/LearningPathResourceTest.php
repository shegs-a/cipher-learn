<?php

declare(strict_types=1);

use App\Filament\Resources\LearningPathResource;
use App\Filament\Resources\LearningPathResource\Pages\EditLearningPath;
use App\Filament\Resources\LearningPathResource\RelationManagers\CoursesRelationManager;
use App\Models\Course;
use App\Models\LearningPath;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
});

/** Sign in as a user holding $role in the tenant's team context (roles seeded). */
function pathActingAs(Tenant $tenant, string $role): User
{
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    app(Tenancy::class)->set($tenant->id);
    app(RolesAndPermissionsSeeder::class)->run();
    $user->assignRole($role);
    test()->actingAs($user);

    return $user;
}

it('lets learning_paths roles author paths, and keeps others out', function () {
    pathActingAs($this->tenant, 'L&D Manager');
    expect(LearningPathResource::canViewAny())->toBeTrue()
        ->and(LearningPathResource::canCreate())->toBeTrue();

    pathActingAs($this->tenant, 'Content Administrator');
    expect(LearningPathResource::canViewAny())->toBeTrue();

    pathActingAs($this->tenant, 'Learner');
    expect(LearningPathResource::canViewAny())->toBeFalse();
});

it('renders the learning paths list for an authorised user', function () {
    $admin = pathActingAs($this->tenant, 'Tenant Admin');

    $this->actingAs($admin)->get(LearningPathResource::getUrl('index'))->assertSuccessful();
});

it('attaches a course to a path via the relation manager', function () {
    // Regression: the Attach action needs the inverse Course::learningPaths()
    // relation to resolve attachable records — without it, mounting it 500s.
    pathActingAs($this->tenant, 'L&D Manager');

    [$path, $course] = app(Tenancy::class)->runFor($this->tenant, fn () => [
        LearningPath::factory()->create(),
        Course::factory()->create(),
    ]);

    Livewire::test(CoursesRelationManager::class, [
        'ownerRecord' => $path,
        'pageClass' => EditLearningPath::class,
    ])
        ->mountTableAction('attach')
        ->setTableActionData(['recordId' => $course->id])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    app(Tenancy::class)->runFor($this->tenant, fn () => expect($path->courses()->count())->toBe(1));
});
