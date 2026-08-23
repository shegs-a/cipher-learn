<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Certificate;
use Illuminate\Contracts\View\View;

/**
 * Public certificate verification — the login-free page a third party (a hiring
 * manager, an auditor) opens to confirm a certificate is genuine.
 *
 * No auth, no tenant context. The `serial` is globally unique by design, so the
 * lookup crosses tenants — and because a guest request binds no tenant, the
 * BelongsToTenant global scope isn't applied and the query resolves directly.
 *
 * The page always renders a definite answer (genuine, with the course/holder/
 * dates, or "not found") rather than a bare 404, so a verifier gets a clear
 * result either way. It exposes only what a certificate legitimately attests —
 * holder name, course, issue/expiry — and no other personal data.
 */
final class CertificateVerificationController extends Controller
{
    public function show(string $serial): View
    {
        $certificate = Certificate::query()
            ->with(['course', 'employee', 'tenant'])
            ->where('serial', $serial)
            ->first();

        return view('certificates.verify', [
            'serial' => $serial,
            'certificate' => $certificate,
        ]);
    }
}
