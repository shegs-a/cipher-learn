<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Models\User;
use App\Support\Audit\Auditor;
use App\Support\Tenancy;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * The one door. Authenticates the single `web` guard against `users`, then routes
 * by permission — admins to the admin panel, everyone else to the learner portal.
 *
 * There is deliberately no per-portal login: identity is one thing, and where you
 * land is a function of what your roles permit, not which URL you arrived at.
 */
#[Layout('components.layouts.app')]
class Login extends Component
{
    #[Validate('required|string|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function authenticate(): mixed
    {
        $this->validate();
        $this->ensureIsNotRateLimited();

        // `is_active` is part of the credentials, so a deactivated user simply
        // fails to authenticate — no separate "your account is disabled" path to
        // leak account existence.
        $credentials = [
            'email' => $this->email,
            'password' => $this->password,
            'is_active' => true,
        ];

        if (! Auth::attempt($credentials, $this->remember)) {
            RateLimiter::hit($this->throttleKey());

            // Record the failed attempt for the security trail. There is no
            // authenticated user and no tenant in context yet, so this lands on
            // the platform-level (null-tenant) chain; we keep the attempted email
            // as context (an identifier, not a secret) but never the password.
            app(Auditor::class)->log('auth.login_failed', extraContext: ['email' => $this->email]);

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        // Regenerate the session id on login to prevent session fixation. The
        // Session facade (not request()->session()) so it also works when the
        // component is exercised outside a full HTTP request.
        Session::regenerate();

        /** @var User $user */
        $user = Auth::user();

        // The Filament panel runs AuthenticateSession, which invalidates the
        // session unless it carries the current user's password hash. A panel's
        // own login sets that; this unified /login lives OUTSIDE the panel, so we
        // set it here — otherwise entering /admin can bounce straight back to
        // /login when the session holds a different (or stale) hash.
        $guard = (string) config('auth.defaults.guard');
        Session::put("password_hash_{$guard}", $user->getAuthPassword());

        // Establish the tenant before auditing so this login is recorded on the
        // user's own tenant chain, not the platform one. landingFor() sets it too,
        // but we need it in place for the audit write that precedes the redirect.
        if ($user->tenant_id !== null) {
            app(Tenancy::class)->set($user->tenant_id);
        }
        app(Auditor::class)->log('auth.login');

        return $this->redirect($this->landingFor($user), navigate: false);
    }

    /**
     * Where this identity belongs after login.
     *
     * The tenant must be set first so the permission check reads the right team
     * (TenancyTeamResolver). Operators go to the panel; everyone else — including
     * a plain line Manager, whose home is "My Team" in the portal — to the
     * learner portal. `intended()` is intentionally NOT honoured — a learner
     * deep-linked to /admin would otherwise bounce into a 403.
     */
    private function landingFor(User $user): string
    {
        app(Tenancy::class)->set($user->tenant_id);

        return $user->landsOnAdminPanel(Filament::getPanel('admin'))
            ? Filament::getPanel('admin')->getUrl()
            : route('portal');
    }

    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => "Too many login attempts. Please try again in {$seconds} seconds.",
        ]);
    }

    /** Per-email, per-IP so one attacker can't lock out an unrelated account. */
    private function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }

    public function render(): View
    {
        return view('livewire.auth.login');
    }
}
