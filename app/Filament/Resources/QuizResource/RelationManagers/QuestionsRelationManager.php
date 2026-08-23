<?php

declare(strict_types=1);

namespace App\Filament\Resources\QuizResource\RelationManagers;

use App\Enums\QuestionType;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class QuestionsRelationManager extends RelationManager
{
    protected static string $relationship = 'questions';

    protected static ?string $recordTitleAttribute = 'prompt';

    public function form(Form $form): Form
    {
        return $form->schema([
            Textarea::make('prompt')->required()->rows(2)->columnSpanFull(),
            Select::make('type')
                ->options(collect(QuestionType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()]))
                ->default(QuestionType::Single->value)
                ->required(),
            TextInput::make('points')->numeric()->minValue(1)->default(1)->required(),
            TextInput::make('position')->numeric()->minValue(0)->default(1)->required(),
            Repeater::make('options')
                ->schema([
                    TextInput::make('key')->required()->maxLength(20)->helperText('e.g. a'),
                    TextInput::make('label')->required()->columnSpan(2),
                ])
                ->columns(3)
                ->minItems(2)
                ->defaultItems(4)
                ->reorderable()
                ->columnSpanFull(),
            TagsInput::make('correct_keys')
                ->required()
                ->helperText('Enter the key(s) of the correct option(s), e.g. "a". Stored server-side and never sent to learners.')
                ->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('prompt')
            ->defaultSort('position')
            ->reorderable('position')
            ->columns([
                TextColumn::make('position')->label('#')->alignCenter(),
                TextColumn::make('prompt')->wrap()->limit(80),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (QuestionType $state) => $state->label()),
                TextColumn::make('points')->alignCenter(),
                // Correct answers are deliberately NOT shown in the listing.
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
