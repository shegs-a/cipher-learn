<?php

declare(strict_types=1);

namespace App\Livewire\Portal;

use App\Models\Certificate;
use App\Models\Employee;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The learner's earned certificates — the credential wall the sidebar links to.
 *
 * Their own only: certificates are read through the signed-in user's Employee, so
 * the tenant scope plus the employee filter keep it to this person's records.
 * Each certificate links to its printable page and its public verification URL.
 */
#[Layout('components.layouts.app')]
class Certificates extends Component
{
    public function render(): View
    {
        $employee = auth()->user()?->employee;

        /** @var Collection<int, Certificate> $certificates */
        $certificates = $employee instanceof Employee
            ? $employee->certificates()
                ->with('course')
                ->orderByDesc('issued_at')
                ->get()
            : new Collection;

        return view('livewire.portal.certificates', [
            'certificates' => $certificates,
        ]);
    }
}
