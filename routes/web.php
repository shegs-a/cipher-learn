<?php

use App\Http\Controllers\CertificateController;
use App\Http\Controllers\CertificateVerificationController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ReportExportController;
use App\Livewire\Auth\Login;
use App\Livewire\Portal\Catalogue;
use App\Livewire\Portal\Certificates;
use App\Livewire\Portal\Course;
use App\Livewire\Portal\Dashboard;
use App\Livewire\Portal\Paths;
use App\Livewire\Portal\Quiz;
use App\Livewire\Portal\Team;
use App\Support\Audit\Auditor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * Identity & access (Sprint 3). One login for everyone; where you land is decided
 * by permission (see App\Livewire\Auth\Login). The learner portal is the non-admin
 * destination; the admin panel is Filament at /admin.
 */
Route::get('/login', Login::class)->name('login');

Route::post('/logout', function (Request $request) {
    // Audit before logging out: while the user is still authenticated the Auditor
    // can resolve the actor, and BindCurrentTenant has the tenant in context, so
    // this lands on their own tenant chain.
    app(Auditor::class)->log('auth.logout');

    Auth::guard('web')->logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect()->route('login');
})->name('logout');

Route::get('/portal', Dashboard::class)->middleware('auth')->name('portal');
Route::get('/portal/catalogue', Catalogue::class)->middleware('auth')->name('portal.catalogue');
Route::get('/portal/paths', Paths::class)->middleware('auth')->name('portal.paths');
Route::get('/portal/team', Team::class)->middleware('auth')->name('portal.team');
// The course player takes an enrolment (not a course): the same course means
// different progress per learner, and mount() enforces it is the viewer's own.
Route::get('/portal/courses/{enrollment}', Course::class)->middleware('auth')->name('portal.course');
Route::get('/portal/courses/{enrollment}/assessment', Quiz::class)->middleware('auth')->name('portal.course.quiz');
// Report CSV export for tenant admins (gated reports.view in the controller).
Route::get('/reports/{key}/export', [ReportExportController::class, 'export'])->middleware('auth')->name('reports.export');

Route::get('/portal/certificates', Certificates::class)->middleware('auth')->name('portal.certificates');
Route::get('/portal/certificates/{certificate}', [CertificateController::class, 'show'])->middleware('auth')->name('portal.certificate');

/*
 * Public certificate verification — deliberately outside the auth group. The
 * serial is a globally-unique key; a guest binds no tenant, so the lookup crosses
 * tenants (see CertificateVerificationController).
 */
Route::get('/verify/{serial}', [CertificateVerificationController::class, 'show'])->name('verify.certificate');

/*
 * Operational probes. Kept as plain closures->controller so they stay dead
 * simple and dependency-light. `/healthz` must never touch the DB; `/readyz`
 * verifies dependencies (see HealthController for why they are separate).
 */
Route::get('/healthz', [HealthController::class, 'live'])->name('health.live');
Route::get('/readyz', [HealthController::class, 'ready'])->name('health.ready');
