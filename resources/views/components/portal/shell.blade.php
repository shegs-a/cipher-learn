{{-- Shared learner-portal shell. Single root element so it can be the root of a
     Livewire page view. See App\View\Components\Portal\Shell for the chrome data. --}}
@php
    // The bottom tab bar (and its content padding) only exist on non-drill-down
    // pages; a drill-down (course/quiz) passes `back` and gets a back header instead.
    $isDrill = filled($back);
    // Nav destinations shared by the desktop sidebar and the mobile bottom bar.
    $navBook = 'M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25';
    $navPaths = 'M6 6.878V6a2.25 2.25 0 012.25-2.25h7.5A2.25 2.25 0 0118 6v.878m-12 0c.235-.083.487-.128.75-.128h10.5c.263 0 .515.045.75.128m-12 0A2.25 2.25 0 004.5 9v.878m13.5-3A2.25 2.25 0 0119.5 9v.878m0 0a2.246 2.246 0 00-.75-.128H5.25c-.263 0-.515.045-.75.128m15 0A2.25 2.25 0 0121 12v6a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 18v-6c0-.98.626-1.813 1.5-2.122';
    $navTeam = 'M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z';
    $navCert = 'M16.5 18.75h-9a9.06 9.06 0 01-1.5-.124V6.377c0-.622.377-1.196.982-1.348A11.99 11.99 0 0112 4.75c1.912 0 3.755.29 5.518.83.605.152.982.726.982 1.348v12.249m-9-3h5.25m-5.25 0V9.75m9 6.75l-2.25-1.313M9.75 9.75l2.25 1.313';
@endphp

<div class="min-h-screen bg-gray-50 lg:flex">
    {{-- ── Desktop sidebar (≥ lg) ─────────────────────────────────────────────── --}}
    <aside class="hidden w-64 shrink-0 flex-col border-r border-gray-200 bg-white lg:flex" x-data="{ account: false }">
        <div class="flex items-center gap-2.5 px-6 py-6">
            <span class="flex h-8 w-8 items-center justify-center rounded-[9px] bg-brand">
                <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/></svg>
            </span>
            <span class="text-lg font-bold tracking-tight text-ink">CipherLearn</span>
        </div>

        <nav class="flex-1 space-y-1 px-4 py-2">
            <a href="{{ route('portal') }}" @class(['flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm', 'bg-brand-100 font-semibold text-brand' => $active === 'learning', 'font-medium text-gray-600 hover:bg-gray-50' => $active !== 'learning'])>
                <svg class="h-5 w-5 {{ $active === 'learning' ? '' : 'text-gray-400' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $navBook }}"/></svg>
                My Learning
            </a>
            <a href="{{ route('portal.paths') }}" @class(['flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm', 'bg-brand-100 font-semibold text-brand' => $active === 'paths', 'font-medium text-gray-600 hover:bg-gray-50' => $active !== 'paths'])>
                <svg class="h-5 w-5 {{ $active === 'paths' ? '' : 'text-gray-400' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $navPaths }}"/></svg>
                Learning paths
            </a>

            @if ($canManageTeam)
                <a href="{{ route('portal.team') }}" @class(['flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm', 'bg-brand-100 font-semibold text-brand' => $active === 'team', 'font-medium text-gray-600 hover:bg-gray-50' => $active !== 'team'])>
                    <svg class="h-5 w-5 {{ $active === 'team' ? '' : 'text-gray-400' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $navTeam }}"/></svg>
                    My team
                </a>
            @endif

            <a href="{{ route('portal.certificates') }}" @class(['flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm', 'bg-brand-100 font-semibold text-brand' => $active === 'certificates', 'font-medium text-gray-600 hover:bg-gray-50' => $active !== 'certificates'])>
                <svg class="h-5 w-5 {{ $active === 'certificates' ? '' : 'text-gray-400' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $navCert }}"/></svg>
                Certificates
            </a>
        </nav>

        {{-- Account / switcher (opens upward from the footer). --}}
        <div class="relative border-t border-gray-100 p-3">
            <button @click="account = !account" class="flex w-full items-center gap-3 rounded-xl px-2 py-2 text-left hover:bg-gray-50">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand text-xs font-bold text-white">{{ $initials }}</span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-semibold text-ink">{{ $user->name }}</span>
                    <span class="block truncate text-xs text-gray-500">{{ trim(($jobTitle ?: 'Learner').($location ? ' · '.$location : '')) }}</span>
                </span>
                <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9"/></svg>
            </button>

            <div x-show="account" x-cloak @click.outside="account = false" x-transition
                class="absolute bottom-full left-3 right-3 z-30 mb-2 rounded-2xl border border-gray-200 bg-white p-2 shadow-lg">
                <x-portal.account-menu :user="$user" :initials="$initials" :canReachAdmin="$canReachAdmin" :adminUrl="$adminUrl" />
            </div>
        </div>
    </aside>

    {{-- ── Main column ────────────────────────────────────────────────────────── --}}
    <div class="flex min-w-0 flex-1 flex-col">
        <header class="sticky top-0 z-30 flex items-center justify-between gap-3 border-b border-gray-200 bg-white px-4 py-3 lg:px-8 lg:py-4">
            <div class="flex min-w-0 items-center gap-2">
                @if ($isDrill)
                    <a href="{{ $back }}" class="-ml-1 flex shrink-0 items-center gap-1 rounded-lg p-1 text-gray-500 hover:bg-gray-50">
                        <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                        {{-- Keep the back label on one line; the page title truncates instead.
                             On the narrowest screens the label collapses to just the chevron. --}}
                        <span class="hidden whitespace-nowrap text-sm font-medium sm:inline {{ $backLabel ? '' : 'sm:sr-only' }}">{{ $backLabel ?? 'Back' }}</span>
                    </a>
                @endif
                <h1 class="truncate text-lg font-bold tracking-tight text-ink lg:text-xl">{{ $title }}</h1>
            </div>
            <div class="flex shrink-0 items-center gap-2 lg:gap-3">
                {{ $actions ?? '' }}
                <livewire:portal.notifications-bell />
            </div>
        </header>

        <main class="flex-1 px-4 py-6 sm:px-6 lg:px-8 lg:py-8 {{ $isDrill ? '' : 'pb-24 lg:pb-8' }}">
            {{ $slot }}
        </main>
    </div>

    {{-- ── Mobile bottom tab bar (< lg, top-level pages only) ────────────────────── --}}
    @unless ($isDrill)
        <nav class="fixed inset-x-0 bottom-0 z-40 flex border-t border-gray-200 bg-white/95 backdrop-blur lg:hidden" x-data="{ account: false }">
            @php
                $tab = fn (bool $on) => $on ? 'text-brand' : 'text-gray-400';
            @endphp
            <a href="{{ route('portal') }}" class="flex flex-1 flex-col items-center gap-0.5 py-2 text-[11px] font-medium {{ $tab($active === 'learning') }}">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $navBook }}"/></svg>
                Learning
            </a>
            <a href="{{ route('portal.paths') }}" class="flex flex-1 flex-col items-center gap-0.5 py-2 text-[11px] font-medium {{ $tab($active === 'paths') }}">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $navPaths }}"/></svg>
                Paths
            </a>
            @if ($canManageTeam)
                <a href="{{ route('portal.team') }}" class="flex flex-1 flex-col items-center gap-0.5 py-2 text-[11px] font-medium {{ $tab($active === 'team') }}">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $navTeam }}"/></svg>
                    Team
                </a>
            @endif
            <a href="{{ route('portal.certificates') }}" class="flex flex-1 flex-col items-center gap-0.5 py-2 text-[11px] font-medium {{ $tab($active === 'certificates') }}">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $navCert }}"/></svg>
                Certificates
            </a>
            <button type="button" @click="account = true" class="flex flex-1 flex-col items-center gap-0.5 py-2 text-[11px] font-medium text-gray-400">
                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-brand text-[10px] font-bold text-white">{{ $initials }}</span>
                Profile
            </button>

            {{-- Profile bottom sheet. --}}
            <div x-show="account" x-cloak x-transition.opacity @click="account = false"
                class="fixed inset-0 z-50 bg-black/30">
                <div @click.stop x-show="account" x-transition
                    class="absolute inset-x-0 bottom-0 rounded-t-3xl border-t border-gray-200 bg-white p-4 pb-8">
                    <div class="mx-auto mb-3 h-1 w-10 rounded-full bg-gray-200"></div>
                    <x-portal.account-menu :user="$user" :initials="$initials" :canReachAdmin="$canReachAdmin" :adminUrl="$adminUrl" />
                </div>
            </div>
        </nav>
    @endunless
</div>
