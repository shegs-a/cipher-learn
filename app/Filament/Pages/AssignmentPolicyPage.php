<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Assignment\LeaveGate;
use App\Hris\Sync\LeaveDataStatus;
use App\Models\Tenant;
use App\Support\Audit\Auditor;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

/**
 * Tenant-level assignment policy. Today: whether admins may assign courses and
 * learning paths to employees who are currently on leave (off by default).
 *
 * The page only edits the setting; the policy itself is enforced in the domain
 * services (see {@see LeaveGate}), never here. Gated by
 * `settings.assignment_policy`, and every change is written to the audit trail.
 *
 * @property Form $form
 */
class AssignmentPolicyPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationLabel = 'Assignment policy';

    protected static ?string $title = 'Assignment policy';

    protected static ?string $slug = 'assignment-policy';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.assignment-policy';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('settings.assignment_policy') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            Tenant::SETTING_ALLOW_ASSIGNMENT_ON_LEAVE => $this->tenant()?->allowsAssignmentDuringLeave() ?? false,
        ]);
    }

    public function form(Form $form): Form
    {
        $tenant = $this->tenant();
        $status = app(LeaveDataStatus::class);

        $freshness = 'This organisation’s HR system does not provide leave data, so the leave check has nothing to act on.';

        if ($tenant !== null && $status->isTracked($tenant)) {
            $last = $status->lastSuccessfulSync($tenant);
            $slots = implode(' and ', (array) config('hris.leave.slots', ['06:00', '18:00']));

            $freshness = sprintf(
                'Leave is refreshed from the HR system at %s (%s). Last successful refresh: %s.',
                $slots,
                $tenant->timezone,
                $last === null ? 'never' : $last->setTimezone($tenant->timezone)->format('j M Y, H:i'),
            );
        }

        return $form
            ->schema([
                Section::make('Employees on leave')
                    ->schema([
                        Toggle::make(Tenant::SETTING_ALLOW_ASSIGNMENT_ON_LEAVE)
                            ->label('Allow admins assign courses and learning paths to employees on leave')
                            ->helperText('When enabled, administrators and learning administrators can assign courses and learning paths to employees who are currently on leave. When disabled, such assignments are blocked.'),
                        Placeholder::make('leave_freshness')
                            ->label('Leave data')
                            ->content(new HtmlString(e($freshness))),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $tenant = $this->tenant();

        if ($tenant === null || ! static::canAccess()) {
            return;
        }

        $state = $this->form->getState();
        $new = (bool) ($state[Tenant::SETTING_ALLOW_ASSIGNMENT_ON_LEAVE] ?? false);
        $old = $tenant->allowsAssignmentDuringLeave();

        if ($new !== $old) {
            $settings = $tenant->settings ?? [];
            $settings[Tenant::SETTING_ALLOW_ASSIGNMENT_ON_LEAVE] = $new;
            $tenant->update(['settings' => $settings]);

            app(Auditor::class)->log(
                'settings.assignment_policy_changed',
                $tenant,
                oldValues: [Tenant::SETTING_ALLOW_ASSIGNMENT_ON_LEAVE => $old],
                newValues: [Tenant::SETTING_ALLOW_ASSIGNMENT_ON_LEAVE => $new],
            );
        }

        Notification::make()->title('Assignment policy saved')->success()->send();
    }

    private function tenant(): ?Tenant
    {
        return auth()->user()?->tenant;
    }
}
