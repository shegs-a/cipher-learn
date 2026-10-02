<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Actions\Learning\AssignLearningPath;
use App\Actions\Learning\AssignLearningPathOrgWide;
use App\Filament\Concerns\AuthorizesViaPermissions;
use App\Filament\Resources\LearningPathResource\Pages;
use App\Filament\Resources\LearningPathResource\RelationManagers\CoursesRelationManager;
use App\Filament\Support\AssignmentFeedback;
use App\Models\Employee;
use App\Models\LearningPath;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

/**
 * Authoring for learning paths — a named, ordered bundle of courses. Assigning a
 * path fans out to a normal enrolment per course (see {@see AssignLearningPath}),
 * so the whole learner journey is reused. Gated by `learning_paths.*`.
 */
class LearningPathResource extends Resource
{
    use AuthorizesViaPermissions;

    protected static ?string $model = LearningPath::class;

    protected static string $viewPermission = 'learning_paths.view';

    protected static string $managePermission = 'learning_paths.manage';

    protected static ?string $navigationIcon = 'heroicon-o-squares-plus';

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?string $navigationLabel = 'Learning paths';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->required()->maxLength(255)->columnSpanFull(),
            Textarea::make('description')->rows(3)->columnSpanFull()
                ->helperText('Add courses and set their order after saving, on the Courses tab.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('semibold'),
                TextColumn::make('courses_count')->counts('courses')->label('Courses')->alignCenter(),
                TextColumn::make('path_enrollments_count')->counts('pathEnrollments')->label('Learners')->alignCenter()->toggleable(),
                TextColumn::make('updated_at')->dateTime()->since()->sortable()->toggleable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->actions([
                // Org-wide assignment (HR/L&D): put everyone (or a department) on the
                // whole path, with a shared reason. Gated enrollments.assign_org.
                Action::make('assign')
                    ->label('Assign to employees')
                    ->icon('heroicon-o-user-plus')
                    ->color('gray')
                    ->visible(fn (): bool => auth()->user()?->can('enrollments.assign_org') ?? false)
                    ->modalHeading(fn (LearningPath $record): string => "Assign “{$record->name}”")
                    ->form([
                        Select::make('department')
                            ->label('Assign to')
                            ->options(fn (): array => ['' => 'Everyone in the organisation']
                                + Employee::query()
                                    ->whereNotNull('department')->distinct()->orderBy('department')
                                    ->pluck('department', 'department')->all())
                            ->default(''),
                        Textarea::make('rationale')->label('Why this path?')->required()->rows(3),
                        DatePicker::make('due_at')->label('Due date')->native(false),
                    ])
                    ->action(function (LearningPath $record, array $data): void {
                        $result = app(AssignLearningPathOrgWide::class)->handle(
                            path: $record,
                            rationale: $data['rationale'],
                            department: $data['department'] ?: null,
                            dueAt: filled($data['due_at'] ?? null) ? Carbon::parse($data['due_at']) : null,
                            assignedBy: auth()->user(),
                        );

                        AssignmentFeedback::bulk($result, 'Learning path assignment complete')->send();
                    }),
                EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            CoursesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLearningPaths::route('/'),
            'create' => Pages\CreateLearningPath::route('/create'),
            'edit' => Pages\EditLearningPath::route('/{record}/edit'),
        ];
    }
}
