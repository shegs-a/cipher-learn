<?php

declare(strict_types=1);

namespace App\Filament\Resources\LearningPathResource\RelationManagers;

use App\Enums\CourseStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\AttachAction;
use Filament\Tables\Actions\DetachAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The courses in a learning path, in order. Attach existing courses and drag to
 * reorder — the order is stored on the `learning_path_course` pivot's `position`,
 * which drives the sequence learners see.
 */
class CoursesRelationManager extends RelationManager
{
    protected static string $relationship = 'courses';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $title = 'Courses in this path';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->defaultSort('learning_path_course.position')
            ->reorderable('learning_path_course.position')
            ->columns([
                TextColumn::make('title')->searchable()->wrap()->weight('semibold'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (CourseStatus $state): string => $state->label())
                    ->color(fn (CourseStatus $state): string => match ($state) {
                        CourseStatus::Published => 'success',
                        CourseStatus::Archived => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('lessons_count')->counts('lessons')->label('Lessons')->alignCenter(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['title']),
            ])
            ->actions([
                DetachAction::make(),
            ]);
    }
}
