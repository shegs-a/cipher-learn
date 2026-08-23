<x-layouts.app :title="'Verify certificate · '.$serial">
    <div class="flex min-h-screen items-center justify-center bg-gray-100 px-4 py-10">
        <div class="w-full max-w-md">
            <div class="mb-6 flex items-center justify-center gap-2.5">
                <span class="flex h-8 w-8 items-center justify-center rounded-[9px] bg-brand">
                    <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/></svg>
                </span>
                <span class="text-lg font-bold tracking-tight text-ink">CipherLearn</span>
            </div>

            @if ($certificate)
                @php($expired = $certificate->isExpired())
                <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card">
                    <div class="flex flex-col items-center gap-2 px-6 py-8 text-center {{ $expired ? 'bg-amber-50' : 'bg-green-50' }}">
                        <span class="flex h-14 w-14 items-center justify-center rounded-full {{ $expired ? 'bg-amber-100 text-amber-600' : 'bg-green-100 text-green-600' }}">
                            @if ($expired)
                                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                            @else
                                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            @endif
                        </span>
                        <p class="text-[11px] font-bold uppercase tracking-wider {{ $expired ? 'text-amber-600' : 'text-green-600' }}">
                            {{ $expired ? 'Genuine · recertification due' : 'Genuine certificate' }}
                        </p>
                    </div>

                    <dl class="divide-y divide-gray-100 px-6 py-2 text-sm">
                        <div class="flex justify-between gap-4 py-3">
                            <dt class="text-gray-500">Holder</dt>
                            <dd class="text-right font-medium text-ink">{{ $certificate->employee?->full_name }}</dd>
                        </div>
                        <div class="flex justify-between gap-4 py-3">
                            <dt class="text-gray-500">Course</dt>
                            <dd class="text-right font-medium text-ink">{{ $certificate->course?->title }}</dd>
                        </div>
                        <div class="flex justify-between gap-4 py-3">
                            <dt class="text-gray-500">Awarded by</dt>
                            <dd class="text-right font-medium text-ink">{{ $certificate->tenant?->name }}</dd>
                        </div>
                        <div class="flex justify-between gap-4 py-3">
                            <dt class="text-gray-500">Issued</dt>
                            <dd class="text-right font-medium text-ink">{{ $certificate->issued_at?->format('j F Y') }}</dd>
                        </div>
                        @if ($certificate->expires_at)
                            <div class="flex justify-between gap-4 py-3">
                                <dt class="text-gray-500">{{ $expired ? 'Expired' : 'Valid until' }}</dt>
                                <dd class="text-right font-medium {{ $expired ? 'text-amber-700' : 'text-ink' }}">{{ $certificate->expires_at->format('j F Y') }}</dd>
                            </div>
                        @endif
                    </dl>

                    <p class="border-t border-gray-100 px-6 py-4 text-center font-mono text-xs text-gray-400">{{ $certificate->serial }}</p>
                </div>
            @else
                <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card">
                    <div class="flex flex-col items-center gap-2 bg-red-50 px-6 py-8 text-center">
                        <span class="flex h-14 w-14 items-center justify-center rounded-full bg-red-100 text-red-600">
                            <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </span>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-red-600">No matching certificate</p>
                    </div>
                    <div class="px-6 py-6 text-center">
                        <p class="text-sm leading-relaxed text-gray-600">We couldn't verify a certificate with the serial</p>
                        <p class="mt-2 font-mono text-xs text-gray-500">{{ $serial }}</p>
                        <p class="mt-3 text-sm text-gray-500">Check the serial and try again.</p>
                    </div>
                </div>
            @endif

            <p class="mt-6 text-center text-xs text-gray-400">Certificate verification · CipherLearn</p>
        </div>
    </div>
</x-layouts.app>
