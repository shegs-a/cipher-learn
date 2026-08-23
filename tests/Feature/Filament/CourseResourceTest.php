<?php

declare(strict_types=1);

use App\Filament\Resources\CourseResource\Pages\CreateCourse;
use App\Filament\Resources\CourseResource\Pages\EditCourse;
use App\Filament\Resources\CourseResource\Pages\ListCourses;
use App\Filament\Resources\CourseResource\RelationManagers\LessonsRelationManager;
use App\Models\Course;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    actingAs($this->admin);
    app(Tenancy::class)->set($this->tenant->id);

    // The admin now needs real authority: seed the tenant's roles and grant
    // Tenant Admin, or the permission-gated resources would (correctly) 403.
    app(RolesAndPermissionsSeeder::class)->run();
    $this->admin->assignRole('Tenant Admin');
});

it('creates a course through the Filament panel, stamped with the tenant', function () {
    Livewire::test(CreateCourse::class)
        ->fillForm([
            'title' => 'New Hire Onboarding',
            'slug' => 'new-hire-onboarding',
            'pass_mark' => 70,
            'status' => 'published',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('courses', [
        'slug' => 'new-hire-onboarding',
        'status' => 'published',
        'tenant_id' => $this->tenant->id, // auto-stamped, never entered in the form
    ]);
});

it('only lists the current tenant\'s courses in the panel', function () {
    Course::factory()->create(['title' => 'Ours']);

    // A course belonging to another tenant must not appear.
    $other = Tenant::factory()->create();
    app(Tenancy::class)->runFor($other, fn () => Course::factory()->create(['title' => 'Theirs']));

    Livewire::test(ListCourses::class)
        ->assertCanSeeTableRecords(Course::all())
        ->assertCountTableRecords(1);
});

it('adds a lesson to a course through the relation manager', function () {
    $course = Course::factory()->create();

    Livewire::test(LessonsRelationManager::class, [
        'ownerRecord' => $course,
        'pageClass' => EditCourse::class,
    ])
        ->callTableAction('create', data: [
            'title' => 'Giving feedback that lands',
            'position' => 1,
            'file_size_bytes' => 500_000,
        ])
        ->assertHasNoTableActionErrors();

    $this->assertDatabaseHas('lessons', [
        'course_id' => $course->id,
        'title' => 'Giving feedback that lands',
        'tenant_id' => $this->tenant->id,
    ]);
});
