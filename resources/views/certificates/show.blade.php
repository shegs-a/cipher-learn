<x-layouts.app :title="'Certificate · '.($certificate->course?->title ?? 'Course')">
    <div class="min-h-screen bg-gray-100 px-4 py-10 print:bg-white print:p-0">
        {{-- Actions (hidden when printing). --}}
        <div class="mx-auto mb-6 flex max-w-3xl items-center justify-between print:hidden">
            <a href="{{ route('portal.certificates') }}" class="inline-flex items-center gap-2 text-sm font-medium text-gray-500 hover:text-gray-800">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                Certificates
            </a>
            <button onclick="window.print()" class="inline-flex items-center gap-2 rounded-xl bg-brand px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-600">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.229 2.523a1.125 1.125 0 01-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0021 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 00-1.913-.247M6.34 18H5.25A2.25 2.25 0 013 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 011.913-.247m10.5 0a48.536 48.536 0 00-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659"/></svg>
                Print / Save as PDF
            </button>
        </div>

        {{-- The certificate. --}}
        <article class="mx-auto max-w-3xl bg-white p-2 shadow-card print:max-w-none print:shadow-none">
            <div class="border-2 border-brand/20 p-6 text-center sm:p-14">
                <div class="flex items-center justify-center gap-2.5">
                    <span class="flex h-9 w-9 items-center justify-center rounded-[10px] bg-brand">
                        <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/></svg>
                    </span>
                    <span class="text-xl font-bold tracking-tight text-ink">CipherLearn</span>
                </div>

                <p class="mt-10 text-[11px] font-bold uppercase tracking-[0.25em] text-brand">Certificate of Completion</p>
                <p class="mt-6 text-sm text-gray-500">This certifies that</p>
                <h1 class="mt-2 text-3xl font-bold tracking-tight text-ink sm:text-4xl">{{ $certificate->employee?->full_name }}</h1>
                <p class="mt-6 text-sm text-gray-500">has successfully completed</p>
                <h2 class="mt-2 text-2xl font-semibold text-ink">{{ $certificate->course?->title }}</h2>

                <div class="mx-auto mt-10 flex max-w-lg items-end justify-between gap-6 border-t border-gray-100 pt-6 text-left">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">Issued</p>
                        <p class="mt-1 text-sm font-medium text-ink">{{ $certificate->issued_at?->format('j F Y') }}</p>
                        @if ($certificate->expires_at)
                            <p class="mt-2 text-[11px] font-semibold uppercase tracking-wider text-gray-400">Valid until</p>
                            <p class="mt-1 text-sm font-medium text-ink">{{ $certificate->expires_at->format('j F Y') }}</p>
                        @endif
                    </div>
                    <div class="text-right">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">Awarded by</p>
                        <p class="mt-1 text-sm font-medium text-ink">{{ $certificate->tenant?->name }}</p>
                    </div>
                </div>

                <div class="mt-10 border-t border-gray-100 pt-6">
                    <p class="font-mono text-xs tracking-wide text-gray-500">{{ $certificate->serial }}</p>
                    <p class="mt-1 break-words text-[11px] text-gray-400">Verify at {{ route('verify.certificate', $certificate->serial) }}</p>
                </div>
            </div>
        </article>
    </div>
</x-layouts.app>
