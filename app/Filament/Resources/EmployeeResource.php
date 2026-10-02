<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Actions\Assignment\AssignCourseToEmployees;
use App\Actions\Learning\AssignLearningPathToEmployees;
use App\Enums\CourseStatus;
use App\Enums\EmployeeStatus;
use App\Filament\Resources\EmployeeResource\Pages;
use App\Filament\Support\AssignmentFeedback;
use App\Models\Course;
use App\Models\Employee;
use App\Models\LearningPath;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Read-only view of the workforce synced from the HR system.
 *
 * Deliberately NOT editable in the LMS: the HR system is the source of truth for
 * people. Letting an admin hand-edit an employee here would create a value the
 * next sync silently overwrites — a confusing lie. Creation and deletion are
 * disabled for the same reason; people arrive and leave through the sync (a
 * leaver is marked `exited`, never removed). The only write action offered is
 * "Sync now", which re-runs that authoritative process.
 *
 * NOTE: access is currently limited only by the admin panel itself. Sprint 3
 * (RBAC) gates this behind a permission; nothing here should be read as the
 * final authorization story.
 */
class EmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'People';

    protected static ?int $navigationSort = 1;

    /**
     * Gated by `employees.view` — the workforce ("who anyone is"). A Content
     * Administrator lacks this, so People is invisible to them, which is half
     * the acid test of Sprint 3.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->can('employees.view') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('employees.view') ?? false;
    }

    /** People are never hand-created in the LMS — the HR system owns them. */
    public static function canCreate(): bool
    {
        return false;
    }

    /** Same reason: no editing or deleting a mirror of an external record. */
    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * Used by the read-only View page; Filament disables every field there.
     */
    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('external_id')->label('HRIS id'),
            TextInput::make('first_name'),
            TextInput::make('last_name'),
            TextInput::make('email'),
            TextInput::make('department'),
            TextInput::make('job_title'),
            TextInput::make('location'),
            TextInput::make('status'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('full_name')
                    ->label('Name')
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(['last_name'])
                    ->weight('semibold'),
                TextColumn::make('email')->searchable()->toggleable(),
                TextColumn::make('department')->searchable()->sortable(),
                TextColumn::make('job_title')->label('Job title')->toggleable(),
                TextColumn::make('manager.full_name')
                    ->label('Manager')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('location')->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (EmployeeStatus $state): string => $state->label())
                    ->color(fn (EmployeeStatus $state): string => match ($state) {
                        EmployeeStatus::Active => 'success',
                        EmployeeStatus::Exited => 'gray',
                    }),
                TextColumn::make('external_id')->label('HRIS id')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('last_name')
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(EmployeeStatus::cases())->mapWithKeys(
                        fn (EmployeeStatus $s) => [$s->value => $s->label()]
                    )->all())
                    // Default to hiding leavers — the everyday view is the active
                    // workforce, but they remain one click away (never deleted).
                    ->default(EmployeeStatus::Active->value),
                SelectFilter::make('department')
                    ->options(fn (): array => Employee::query()
                        ->whereNotNull('department')
                        ->distinct()
                        ->orderBy('department')
                        ->pluck('department', 'department')
                        ->all()),
            ])
            ->actions([
                ViewAction::make(),
            ])
            ->bulkActions([
                // Assign to a hand-picked set of people. Same domain services as the
                // org-wide flows, so the leave policy applies identically and the admin
                // sees exactly who was assigned, blocked (on leave) or skipped.
                BulkActionGroup::make([
                    BulkAction::make('assignCourse')
                        ->label('Assign course')
                        ->icon('heroicon-o-academic-cap')
                        ->visible(fn (): bool => auth()->user()?->can('enrollments.assign_org') ?? false)
                        ->form([
                            Select::make('course_id')
                                ->label('Course')
                                ->options(fn (): array => Course::query()
                                    ->where('status', CourseStatus::Published->value)
                                    ->orderBy('title')->pluck('title', 'id')->all())
                                ->searchable()
                                ->required(),
                            Textarea::make('rationale')->label('Why this course?')->required()->rows(3)
                                ->helperText('The reason every assigned learner will see.'),
                            DatePicker::make('due_at')->label('Due date')->native(false),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            /** @var Collection<int, Employee> $records */
                            $result = app(AssignCourseToEmployees::class)->handle(
                                course: Course::query()->findOrFail($data['course_id']),
                                employees: $records,
                                rationale: $data['rationale'],
                                scope: 'selection',
                                dueAt: filled($data['due_at'] ?? null) ? Carbon::parse($data['due_at']) : null,
                                assignedBy: auth()->user(),
                            );

                            AssignmentFeedback::bulk($result, 'Course assignment complete')->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('assignPath')
                        ->label('Assign learning path')
                        ->icon('heroicon-o-map')
                        ->visible(fn (): bool => auth()->user()?->can('enrollments.assign_org') ?? false)
                        ->form([
                            Select::make('path_id')
                                ->label('Learning path')
                                ->options(fn (): array => LearningPath::query()->orderBy('name')->pluck('name', 'id')->all())
                                ->searchable()
                                ->required(),
                            Textarea::make('rationale')->label('Why this path?')->required()->rows(3),
                            DatePicker::make('due_at')->label('Due date')->native(false),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            /** @var Collection<int, Employee> $records */
                            $result = app(AssignLearningPathToEmployees::class)->handle(
                                path: LearningPath::query()->findOrFail($data['path_id']),
                                employees: $records,
                                rationale: $data['rationale'],
                                scope: 'selection',
                                dueAt: filled($data['due_at'] ?? null) ? Carbon::parse($data['due_at']) : null,
                                assignedBy: auth()->user(),
                            );

                            AssignmentFeedback::bulk($result, 'Learning path assignment complete')->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployees::route('/'),
            'view' => Pages\ViewEmployee::route('/{record}'),
        ];
    }
}
