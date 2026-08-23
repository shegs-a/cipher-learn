<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Filament\Pages\Concerns\RendersReport;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;

class OverdueReportPage extends Page implements HasForms, HasTable
{
    use InteractsWithForms, InteractsWithTable, RendersReport {
        RendersReport::form insteadof InteractsWithForms;
        RendersReport::table insteadof InteractsWithTable;
    }

    protected static ?string $navigationGroup = 'Reports';

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'Overdue / at-risk';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.report';

    protected function reportKey(): string
    {
        return 'overdue';
    }
}
