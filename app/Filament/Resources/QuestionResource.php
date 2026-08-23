<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\QuestionType;
use App\Filament\Concerns\AuthorizesViaPermissions;
use App\Filament\Resources\QuestionResource\Pages;
use App\Models\Question;
use App\Models\Quiz;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * A flat view of every quiz question. Primary authoring is the Questions
 * relation manager on a quiz; correct answers are never shown in the listing.
 */
class QuestionResource extends Resource
{
    use AuthorizesViaPermissions;

    protected static ?string $model = Question::class;

    protected static string $viewPermission = 'courses.view';

    protected static string $managePermission = 'courses.manage';

    protected static ?string $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('quiz_id')
                ->relationship('quiz', 'title')
                ->getOptionLabelFromRecordUsing(fn (Quiz $record) => $record->course->title)
                ->searchable()->preload()->required(),
            Textarea::make('prompt')->required()->rows(2)->columnSpanFull(),
            Select::make('type')
                ->options(collect(QuestionType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()]))
                ->default(QuestionType::Single->value)->required(),
            TextInput::make('points')->numeric()->minValue(1)->default(1)->required(),
            Repeater::make('options')
                ->schema([
                    TextInput::make('key')->required()->maxLength(20),
                    TextInput::make('label')->required()->columnSpan(2),
                ])
                ->columns(3)->minItems(2)->defaultItems(4)->columnSpanFull(),
            TagsInput::make('correct_keys')->required()->columnSpanFull()
                ->helperText('Correct option key(s). Stored server-side, never sent to learners.'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('quiz.course.title')->label('Course')->searchable()->sortable(),
                TextColumn::make('prompt')->wrap()->limit(80)->searchable(),
                TextColumn::make('type')->badge()->formatStateUsing(fn (QuestionType $state) => $state->label()),
                TextColumn::make('points')->alignCenter(),
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuestions::route('/'),
            'create' => Pages\CreateQuestion::route('/create'),
            'edit' => Pages\EditQuestion::route('/{record}/edit'),
        ];
    }
}
