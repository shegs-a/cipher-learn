<?php

declare(strict_types=1);

namespace App\Filament\Resources\CourseResource\RelationManagers;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Number;

class LessonsRelationManager extends RelationManager
{
    protected static string $relationship = 'lessons';

    protected static ?string $recordTitleAttribute = 'title';

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('title')->required()->maxLength(255)->columnSpanFull(),
            Textarea::make('content')
                ->rows(8)
                ->helperText('Text-first — no autoplay media. Keep it light for metered mobile data.')
                ->columnSpanFull(),
            TextInput::make('position')->numeric()->default(1)->minValue(0)->required(),
            TextInput::make('estimated_minutes')->numeric()->minValue(1)->suffix('min'),
            TextInput::make('file_size_bytes')
                ->numeric()->minValue(0)->default(0)->suffix('bytes')
                ->helperText('Download size for this module, shown to learners.'),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->defaultSort('position')
            ->reorderable('position')
            ->columns([
                TextColumn::make('position')->label('#')->alignCenter(),
                TextColumn::make('title')->searchable()->wrap(),
                TextColumn::make('estimated_minutes')->label('Est.')->suffix(' min')->alignCenter(),
                TextColumn::make('file_size_bytes')
                    ->label('Size')
                    ->formatStateUsing(fn (int $state) => Number::fileSize($state))
                    ->alignEnd(),
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
