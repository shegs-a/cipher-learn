<?php

declare(strict_types=1);

namespace App\Filament\Pages\Concerns;

use App\Support\Reports\Report;
use App\Support\Reports\ReportRegistry;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use LogicException;

/**
 * Shared behaviour for the report pages: it renders a {@see Report}'s declared
 * filters as a live form, its columns as a table (driven by the report's own
 * tenant-scoped query and row mapping), and a "Download CSV" action pointing at
 * the export route with the current filters. The concrete page only names its
 * report key and its navigation.
 *
 * The on-screen table and the export are the *same* query — both go through
 * `report()->query($this->filters)` — so what you see is exactly what you export.
 */
trait RendersReport
{
    /**
     * Filter state, bound to the filter form (and forwarded to the export URL).
     *
     * @var array<string, mixed>
     */
    public array $filters = [];

    /** The concrete page names which report it renders. */
    abstract protected function reportKey(): string;

    protected function report(): Report
    {
        return app(ReportRegistry::class)->find($this->reportKey())
            ?? throw new LogicException("Unknown report [{$this->reportKey()}].");
    }

    /** Gated by reports.view — Tenant Admin / L&D / Manager, not Content Admin. */
    public static function canAccess(): bool
    {
        return auth()->user()?->can('reports.view') ?? false;
    }

    public function getTitle(): string|Htmlable
    {
        return $this->report()->label();
    }

    public function form(Form $form): Form
    {
        $fields = [];

        foreach ($this->report()->filters() as $key => $def) {
            /** @var array{label: string, type: string, options?: array<string, string>} $def */
            // Native controls (not Choices.js/JS date picker): a plain <select> /
            // <input type=date> reliably submits its value with the Filter form.
            // Deliberately NOT ->live() — filters apply only when Filter is pressed.
            $fields[] = match ($def['type']) {
                'date' => DatePicker::make($key)->label($def['label'])->native(),
                default => Select::make($key)->label($def['label'])->options($def['options'] ?? [])->native(),
            };
        }

        return $form->schema([Grid::make(3)->schema($fields)])->statePath('filters');
    }

    /**
     * Apply the chosen filters. Pulls the current form values (the deferred
     * dropdowns are submitted with this request), then resets the table so it
     * re-queries — only the table below updates, without a full-page reload.
     */
    public function applyFilters(): void
    {
        /** @var array<string, mixed> $state */
        $state = $this->getForm('form')?->getState() ?? [];
        $this->filters = $state;

        $this->resetTable();
    }

    /** Clear all filters and refresh the table. */
    public function resetFilters(): void
    {
        $this->filters = [];
        $this->getForm('form')?->fill();
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $report = $this->report();

        $columns = [];
        foreach ($report->columns() as $key => $label) {
            $columns[] = TextColumn::make($key)
                ->label($label)
                ->getStateUsing(fn ($record) => $report->row($record)[$key] ?? null)
                ->wrap();
        }

        return $table
            ->query(fn () => $report->query($this->filters))
            ->columns($columns)
            ->actions($this->tableActions())
            ->paginated([25, 50, 100]);
    }

    /**
     * Per-row actions for the report table. Empty by default; a report page (e.g.
     * Enrolments) overrides this to add workflow actions such as approve/reject.
     *
     * @return array<int, \Filament\Tables\Actions\Action>
     */
    protected function tableActions(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Download CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->url(fn (): string => route('reports.export', array_merge(
                    ['key' => $this->report()->key()],
                    array_filter($this->filters, fn ($v): bool => $v !== null && $v !== ''),
                )))
                ->openUrlInNewTab(),
        ];
    }
}
