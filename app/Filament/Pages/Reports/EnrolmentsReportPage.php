<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Actions\Assignment\AssignCourse;
use App\Enums\EnrollmentStatus;
use App\Filament\Pages\Concerns\RendersReport;
use App\Filament\Support\AssignmentFeedback;
use App\Models\Enrollment;
use App\Notifications\AccessRequestRejectedNotification;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * The single Enrolments view (under Reports): filter the org's assignments by
 * course/department/status/source/date, export to CSV, and — for a learner
 * **request** — approve or reject it inline. The old separate People › Enrolments
 * resource was merged into this page.
 */
class EnrolmentsReportPage extends Page implements HasForms, HasTable
{
    use InteractsWithForms, InteractsWithTable, RendersReport {
        RendersReport::form insteadof InteractsWithForms;
        RendersReport::table insteadof InteractsWithTable;
    }

    protected static ?string $navigationGroup = 'Reports';

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Enrolments';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.report';

    protected function reportKey(): string
    {
        return 'enrolments';
    }

    /**
     * Approve / reject a learner request, right from the report row. Approval runs
     * {@see AssignCourse} (flipping requested → assigned with the approver's reason);
     * rejection cancels it and notifies the learner. Both scoped by the
     * `assign-course-to` gate.
     *
     * @return array<int, Action>
     */
    protected function tableActions(): array
    {
        return [
            Action::make('approve')
                ->icon('heroicon-o-check')
                ->color('success')
                ->visible(fn (Enrollment $record): bool => $record->status === EnrollmentStatus::Requested
                    && Gate::allows('assign-course-to', $record->employee))
                ->form([
                    Textarea::make('rationale')->label('Reason for approving')->required()->rows(3)
                        ->helperText('The learner will see this as why the course was assigned.'),
                    DatePicker::make('due_at')->label('Due date')->native(false),
                ])
                ->action(function (Enrollment $record, array $data): void {
                    $result = app(AssignCourse::class)->attempt(
                        employee: $record->employee,
                        course: $record->course,
                        rationale: $data['rationale'],
                        assignedBy: auth()->user(),
                        dueAt: filled($data['due_at']) ? Carbon::parse($data['due_at']) : null,
                    );

                    // Approving a request IS an assignment, so the leave policy applies:
                    // a blocked approval leaves the request pending and says why.
                    if ($result->isBlocked()) {
                        AssignmentFeedback::single($result, $record->course->title)->send();
                    }

                    $this->resetTable();
                }),

            Action::make('reject')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (Enrollment $record): bool => $record->status === EnrollmentStatus::Requested
                    && Gate::allows('assign-course-to', $record->employee))
                ->action(function (Enrollment $record): void {
                    $record->update(['status' => EnrollmentStatus::Cancelled]);

                    $record->loadMissing(['employee', 'course']);
                    $record->employee?->user?->notify(new AccessRequestRejectedNotification(
                        courseTitle: $record->course->title,
                    ));

                    $this->resetTable();
                }),
        ];
    }
}
