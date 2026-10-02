<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Actions\Assignment\AssignCourseOrgWide;
use App\Enums\CourseStatus;
use App\Filament\Concerns\AuthorizesViaPermissions;
use App\Filament\Resources\CourseResource\Pages;
use App\Filament\Resources\CourseResource\RelationManagers\LessonsRelationManager;
use App\Filament\Support\AssignmentFeedback;
use App\Models\Course;
use App\Models\Employee;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CourseResource extends Resource
{
    use AuthorizesViaPermissions;

    protected static ?string $model = Course::class;

    protected static string $viewPermission = 'courses.view';

    protected static string $managePermission = 'courses.manage';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('title')
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                // Auto-fill the slug from the title on create only.
                ->afterStateUpdated(function (string $operation, ?string $state, Set $set): void {
                    if ($operation === 'create' && filled($state)) {
                        $set('slug', Str::slug($state));
                    }
                }),
            TextInput::make('slug')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true) // scoped to tenant by the global scope
                ->helperText('URL-safe identifier, unique within your organisation.'),
            Textarea::make('summary')
                ->rows(3)
                ->columnSpanFull(),
            TextInput::make('thumbnail_url')
                ->label('Thumbnail URL')
                ->url()
                ->helperText('Optional image shown on the learner dashboard; a branded placeholder is used when empty.')
                ->columnSpanFull(),
            TagsInput::make('tags')
                ->helperText('Used for lexical matching when no rule applies.')
                ->columnSpanFull(),
            TextInput::make('pass_mark')
                ->numeric()->minValue(0)->maxValue(100)->default(70)->suffix('%')->required(),
            Select::make('status')
                ->options(collect(CourseStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]))
                ->default(CourseStatus::Draft->value)
                ->required(),
            TextInput::make('max_attempts')
                ->numeric()->minValue(1)
                ->helperText('Leave blank for unlimited attempts.'),
            TextInput::make('recert_months')
                ->numeric()->minValue(1)->suffix('months')
                ->helperText('Leave blank if the course never expires.'),
            TextInput::make('estimated_minutes')
                ->numeric()->minValue(1)->suffix('min'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable()->weight('semibold'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (CourseStatus $state) => $state->label())
                    ->color(fn (CourseStatus $state) => match ($state) {
                        CourseStatus::Published => 'success',
                        CourseStatus::Draft => 'gray',
                        CourseStatus::Archived => 'warning',
                    }),
                TextColumn::make('lessons_count')->counts('lessons')->label('Lessons')->alignCenter(),
                TextColumn::make('pass_mark')->suffix('%')->alignCenter()->sortable(),
                TextColumn::make('updated_at')->dateTime()->since()->sortable()->toggleable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->actions([
                // Org-wide assignment (HR/L&D): assign this course to everyone, or
                // one department, with a shared reason. Gated enrollments.assign_org.
                Action::make('assignOrg')
                    ->label('Assign to employees')
                    ->icon('heroicon-o-user-plus')
                    ->color('gray')
                    ->visible(fn (): bool => auth()->user()?->can('enrollments.assign_org') ?? false)
                    ->modalHeading(fn (Course $record): string => "Assign “{$record->title}”")
                    ->form([
                        Select::make('department')
                            ->label('Assign to')
                            ->options(fn (): array => ['' => 'Everyone in the organisation']
                                + Employee::query()
                                    ->whereNotNull('department')->distinct()->orderBy('department')
                                    ->pluck('department', 'department')->all())
                            ->default('')
                            ->helperText('Everyone, or a single department. Active employees only.'),
                        Textarea::make('rationale')->label('Why this course?')->required()->rows(3)
                            ->helperText('The reason every assigned learner will see.'),
                        DatePicker::make('due_at')->label('Due date')->native(false),
                    ])
                    ->action(function (Course $record, array $data): void {
                        $result = app(AssignCourseOrgWide::class)->handle(
                            course: $record,
                            rationale: $data['rationale'],
                            department: $data['department'] ?: null,
                            dueAt: filled($data['due_at']) ? Carbon::parse($data['due_at']) : null,
                            assignedBy: auth()->user(),
                        );

                        AssignmentFeedback::bulk($result, 'Course assignment complete')->send();
                    }),
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            LessonsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCourses::route('/'),
            'create' => Pages\CreateCourse::route('/create'),
            'edit' => Pages\EditCourse::route('/{record}/edit'),
        ];
    }
}
