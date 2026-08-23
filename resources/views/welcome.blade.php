{{-- Sprint 0 placeholder. Its only job is to prove the brand renders: Inter across
     weights 300–700 and the primary #4f46e5. Real screens are re-implemented from
     /design in later sprints. --}}
<x-layouts.app title="CipherLearn">
    <main class="mx-auto flex min-h-screen max-w-2xl flex-col items-center justify-center px-6 py-16">
        <div class="w-full rounded-panel border border-gray-200 bg-white p-8 shadow-card sm:p-10">
            {{-- Wordmark: logo mark drawn in CSS/SVG exactly as the design does it. --}}
            <div class="flex items-center gap-3">
                <span class="flex h-7 w-7 items-center justify-center rounded-[7px] bg-brand">
                    <svg class="h-4 w-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/></svg>
                </span>
                <span class="text-xl font-bold tracking-tight text-ink">CipherLearn</span>
                <span class="rounded-full border border-brand-200 bg-brand-100 px-2.5 py-1 text-[11px] font-semibold text-brand">Sprint&nbsp;0</span>
            </div>

            <h1 class="mt-8 text-2xl font-semibold tracking-tight text-gray-900">
                Foundation is up.
            </h1>
            <p class="mt-2 text-[15px] leading-relaxed text-gray-500">
                An HRIS-native LMS that assigns training against measured performance gaps —
                with a written rationale the employee and their manager can both see.
            </p>

            {{-- Type scale proof: Inter must render at every loaded weight. --}}
            <div class="mt-8 border-t border-gray-100 pt-6">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">Typeface · Inter</p>
                <div class="mt-3 space-y-1 text-gray-900">
                    <p class="text-lg font-light">Inter Light 300 — the quick brown fox</p>
                    <p class="text-lg font-normal">Inter Regular 400 — the quick brown fox</p>
                    <p class="text-lg font-medium">Inter Medium 500 — the quick brown fox</p>
                    <p class="text-lg font-semibold">Inter Semibold 600 — the quick brown fox</p>
                    <p class="text-lg font-bold">Inter Bold 700 — the quick brown fox</p>
                </div>
            </div>

            {{-- Colour proof: the brand ramp plus semantic tokens. --}}
            <div class="mt-6 border-t border-gray-100 pt-6">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">Palette</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <span class="flex flex-col items-start gap-1">
                        <span class="h-9 w-16 rounded-lg bg-brand"></span>
                        <span class="text-[11px] text-gray-500">#4f46e5</span>
                    </span>
                    <span class="flex flex-col items-start gap-1">
                        <span class="h-9 w-16 rounded-lg bg-brand-700"></span>
                        <span class="text-[11px] text-gray-500">hover</span>
                    </span>
                    <span class="flex flex-col items-start gap-1">
                        <span class="h-9 w-16 rounded-lg bg-danger"></span>
                        <span class="text-[11px] text-gray-500">danger</span>
                    </span>
                    <span class="flex flex-col items-start gap-1">
                        <span class="h-9 w-16 rounded-lg bg-success"></span>
                        <span class="text-[11px] text-gray-500">success</span>
                    </span>
                    <span class="flex flex-col items-start gap-1">
                        <span class="h-9 w-16 rounded-lg bg-warning"></span>
                        <span class="text-[11px] text-gray-500">warning</span>
                    </span>
                </div>
            </div>

            {{-- Health endpoints — the acceptance target for Sprint 0. --}}
            <div class="mt-8 flex flex-wrap items-center gap-3">
                <a href="/healthz" class="inline-flex items-center gap-2 rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-brand-hover">
                    Liveness · /healthz
                </a>
                <a href="/readyz" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 transition-colors hover:bg-gray-50">
                    Readiness · /readyz
                </a>
            </div>
        </div>

        <p class="mt-6 text-center text-xs text-gray-400">
            Laravel {{ app()->version() }} · PHP {{ PHP_VERSION }} · CipherLearn
        </p>
    </main>
</x-layouts.app>
