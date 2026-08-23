<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaPermissions;
use App\Filament\Resources\LessonResource\Pages;
use App\Models\Lesson;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Number;

/**
 * A flat view of every lesson. The primary authoring flow is the Lessons
 * relation manager on a course; this resource exists for cross-course browsing.
 */
class LessonResource extends Resource
{
    use AuthorizesViaPermissions;

    protected static ?string $model = Lesson::class;

    protected static string $viewPermission = 'courses.view';

    protected static string $managePermission = 'courses.manage';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('course_id')
                ->relationship('course', 'title')
                ->searchable()->preload()->required(),
            TextInput::make('title')->required()->maxLength(255),
            Textarea::make('content')->rows(8)->columnSpanFull()
                ->helperText('Text-first — no autoplay media.'),
            TextInput::make('position')->numeric()->minValue(0)->default(1)->required(),
            TextInput::make('estimated_minutes')->numeric()->minValue(1)->suffix('min'),
            TextInput::make('file_size_bytes')->numeric()->minValue(0)->default(0)->suffix('bytes'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('course.title')->label('Course')->searchable()->sortable(),
                TextColumn::make('position')->label('#')->alignCenter()->sortable(),
                TextColumn::make('title')->searchable()->wrap(),
                TextColumn::make('file_size_bytes')->label('Size')
                    ->formatStateUsing(fn (int $state) => Number::fileSize($state))->alignEnd(),
            ])
            ->defaultSort('course_id')
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
            'index' => Pages\ListLessons::route('/'),
            'create' => Pages\CreateLesson::route('/create'),
            'edit' => Pages\EditLesson::route('/{record}/edit'),
        ];
    }
}
