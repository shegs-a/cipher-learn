<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Certificate;
use Illuminate\Contracts\View\View;

/**
 * The learner's own printable certificate — the credential itself, laid out for
 * screen or "print to PDF" (Sprint 5 ships no PDF library; the browser's print
 * dialog covers the download).
 */
final class CertificateController extends Controller
{
    public function show(Certificate $certificate): View
    {
        // Route-model binding is already tenant-scoped, so only a same-tenant
        // certificate resolves. On top of that, it must be the signed-in user's
        // own — never another employee's.
        $employee = auth()->user()?->employee;
        abort_unless($employee !== null && $certificate->employee_id === $employee->id, 404);

        $certificate->loadMissing(['course', 'employee', 'tenant']);

        return view('certificates.show', ['certificate' => $certificate]);
    }
}
