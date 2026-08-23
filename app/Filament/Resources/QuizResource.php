<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaPermissions;
use App\Filament\Resources\QuizResource\Pages;
use App\Filament\Resources\QuizResource\RelationManagers\QuestionsRelationManager;
use App\Models\Quiz;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class QuizResource extends Resource
{
    use AuthorizesViaPermissions;

    protected static ?string $model = Quiz::class;

    protected static string $viewPermission = 'courses.view';

    protected static string $managePermission = 'courses.manage';

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('course_id')
                ->relationship('course', 'title')
                ->searchable()->preload()->required()
                ->helperText('The course this assessment gates.'),
            TextInput::make('title')->required()->default('Assessment')->maxLength(255),
            TextInput::make('pass_mark')
                ->numeric()->minValue(0)->maxValue(100)->suffix('%')
                ->helperText('Leave blank to inherit the course pass mark.'),
            TextInput::make('max_attempts')
                ->numeric()->minValue(1)
                ->helperText('Leave blank to inherit the course attempt cap.'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('course.title')->label('Course')->searchable()->sortable(),
                TextColumn::make('title'),
                TextColumn::make('questions_count')->counts('questions')->label('Questions')->alignCenter(),
                TextColumn::make('pass_mark')->placeholder('inherited')->suffix('%')->alignCenter(),
            ])
            ->actions([
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
            QuestionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuizzes::route('/'),
            'create' => Pages\CreateQuiz::route('/create'),
            'edit' => Pages\EditQuiz::route('/{record}/edit'),
        ];
    }
}
