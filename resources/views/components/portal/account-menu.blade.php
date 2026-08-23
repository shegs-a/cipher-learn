@props(['user', 'initials', 'canReachAdmin' => false, 'adminUrl' => '#'])

{{-- The account / portal-switcher body, shared by the desktop sidebar dropdown and
     the mobile Profile bottom sheet so both stay in step. --}}
<div>
    <div class="flex items-center gap-3 px-3 py-2.5">
        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-brand text-xs font-bold text-white">{{ $initials }}</span>
        <div class="min-w-0">
            <p class="truncate text-sm font-semibold text-ink">{{ $user->name }}</p>
            <p class="truncate text-xs text-gray-500">{{ $user->email }}</p>
        </div>
    </div>

    <p class="px-3 pb-1 pt-1 text-[11px] font-semibold uppercase tracking-wider text-gray-400">Switch portal</p>

    <div class="flex items-center gap-3 rounded-xl bg-brand-100 px-3 py-2.5">
        <div class="flex-1">
            <p class="text-sm font-semibold text-ink">Learner</p>
            <p class="text-xs text-gray-500">Your courses &amp; progress</p>
        </div>
        <span class="text-brand">✓</span>
    </div>

    @if ($canReachAdmin)
        <a href="{{ $adminUrl }}" class="mt-1 flex items-center gap-3 rounded-xl px-3 py-2.5 hover:bg-gray-50">
            <div class="flex-1">
                <p class="text-sm font-semibold text-ink">Admin console</p>
                <p class="text-xs text-gray-500">Manage courses, people &amp; roles</p>
            </div>
            <span class="text-gray-300">›</span>
        </a>
    @endif

    <div class="my-1.5 border-t border-gray-100"></div>
    <form method="POST" action="{{ route('logout') }}" class="px-1">
        @csrf
        <button type="submit" class="w-full rounded-xl px-3 py-2.5 text-left text-sm font-medium text-gray-600 hover:bg-gray-50">Sign out</button>
    </form>
</div>
