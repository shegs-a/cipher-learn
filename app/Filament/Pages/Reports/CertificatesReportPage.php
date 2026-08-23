<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Filament\Pages\Concerns\RendersReport;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;

class CertificatesReportPage extends Page implements HasForms, HasTable
{
    use InteractsWithForms, InteractsWithTable, RendersReport {
        RendersReport::form insteadof InteractsWithForms;
        RendersReport::table insteadof InteractsWithTable;
    }

    protected static ?string $navigationGroup = 'Reports';

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationLabel = 'Certificates';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.report';

    protected function reportKey(): string
    {
        return 'certificates';
    }
}
