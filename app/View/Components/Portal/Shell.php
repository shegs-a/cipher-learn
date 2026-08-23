<?php

declare(strict_types=1);

namespace App\View\Components\Portal;

use App\Models\Employee;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\View\Component;

/**
 * The shared learner-portal shell: brand, navigation and account switcher in ONE
 * place, so every portal page gets identical, responsive chrome instead of the
 * hand-rolled sidebar each page used to carry.
 *
 * - **Desktop (≥ lg):** a persistent left sidebar (nav + account switcher).
 * - **Mobile/tablet (< lg):** a sticky top bar + a fixed **bottom tab bar**
 *   (Learning · Paths · Certificates · Profile, plus **My team** for managers) —
 *   matching the mobile-first design.
 *
 * The shell computes its OWN chrome from the signed-in user (name/initials, job
 * title, roles → team & admin access), so a page only supplies its `title`, an
 * optional top-bar `actions` slot, and its content. **Drill-down** pages (a course
 * player, a quiz) pass a `back` target: on mobile that swaps the top bar for a
 * back header and hides the bottom nav, the way the design's sub-screens behave.
 */
final class Shell extends Component
{
    public User $user;

    public string $initials;

    public ?string $jobTitle;

    public ?string $location;

    /** Whether to show the "My team" destination (managers / assigners only). */
    public bool $canManageTeam;

    public bool $canReachAdmin;

    public string $adminUrl;

    /** Which top-level nav item is current, for highlight state. */
    public string $active;

    /**
     * @param  string  $title  Top-bar heading for this page.
     * @param  string|null  $back  URL to go back to; when set, mobile shows a back header (drill-down) and hides the bottom nav.
     * @param  string|null  $backLabel  Label beside the mobile back chevron.
     */
    public function __construct(
        public string $title = '',
        public ?string $back = null,
        public ?string $backLabel = null,
    ) {
        /** @var User $user */
        $user = auth()->user();
        $this->user = $user;

        $employee = $user->employee;
        $this->initials = $this->initialsFor($user->name);
        $this->jobTitle = $employee?->job_title;
        $this->location = $employee?->location;

        // Same rule the pages used: manages people, or can assign training.
        $this->canManageTeam = $user->can('enrollments.assign')
            || ($employee instanceof Employee && $employee->reports()->exists());

        $panel = Filament::getPanel('admin');
        $this->canReachAdmin = $user->canAccessPanel($panel);
        $this->adminUrl = $panel->getUrl();

        $this->active = $this->activeFromRoute();
    }

    public function render(): View
    {
        return view('components.portal.shell');
    }

    /** Map the current route to a nav key so the matching item is highlighted. */
    private function activeFromRoute(): string
    {
        return match (request()->route()?->getName()) {
            'portal', 'portal.catalogue' => 'learning',
            'portal.paths' => 'paths',
            'portal.certificates' => 'certificates',
            'portal.team' => 'team',
            default => '',
        };
    }

    private function initialsFor(string $name): string
    {
        return Str::of($name)->explode(' ')->filter()->take(2)
            ->map(fn (string $part) => Str::upper(Str::substr($part, 0, 1)))
            ->implode('');
    }
}
