<x-portal.shell title="Certificates">
    <div class="mx-auto w-full max-w-4xl space-y-4">
        @forelse ($certificates as $certificate)
            @php($expired = $certificate->isExpired())
            <article class="flex flex-col gap-4 rounded-2xl border border-gray-200 bg-white p-5 shadow-card sm:flex-row sm:items-center sm:p-6">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl {{ $expired ? 'bg-amber-100 text-amber-600' : 'bg-brand-100 text-brand' }}">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 18.75h-9a9.06 9.06 0 01-1.5-.124V6.377c0-.622.377-1.196.982-1.348A11.99 11.99 0 0112 4.75c1.912 0 3.755.29 5.518.83.605.152.982.726.982 1.348v12.249m-9-3h5.25m-5.25 0V9.75m9 6.75l-2.25-1.313M9.75 9.75l2.25 1.313"/></svg>
                </span>

                <div class="min-w-0 flex-1">
                    <h2 class="truncate text-[15px] font-bold text-ink">{{ $certificate->course?->title ?? 'Course' }}</h2>
                    <p class="mt-0.5 text-[13px] text-gray-500">
                        Issued {{ $certificate->issued_at?->format('j M Y') }}
                        @if ($certificate->expires_at)
                            · {{ $expired ? 'Expired' : 'Valid until' }} {{ $certificate->expires_at->format('j M Y') }}
                        @endif
                    </p>
                    <p class="mt-1 font-mono text-xs text-gray-400">{{ $certificate->serial }}</p>
                </div>

                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    @if ($expired)
                        <span class="rounded-full bg-amber-100 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-amber-700">Recert due</span>
                    @endif
                    <a href="{{ route('verify.certificate', $certificate->serial) }}" target="_blank"
                        class="rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50">Verify</a>
                    <a href="{{ route('portal.certificate', $certificate) }}"
                        class="rounded-xl bg-brand px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-600">View</a>
                </div>
            </article>
        @empty
            <div class="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-16 text-center">
                <p class="text-[15px] font-semibold text-gray-700">No certificates yet.</p>
                <p class="mt-1 text-sm text-gray-500">Complete a course and pass its assessment to earn your first certificate — it'll show up here with a shareable verification link.</p>
            </div>
        @endforelse
    </div>
</x-portal.shell>
