<x-portal.shell title="My Learning">
    <x-slot:actions>
        <a href="{{ route('portal.catalogue') }}"
            class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 lg:px-4">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/></svg>
            <span class="hidden sm:inline">Browse catalogue</span>
        </a>
    </x-slot:actions>

    {{-- A warm greeting up top, as in the design. --}}
    <p class="text-sm text-gray-500">{{ $greeting }} <span class="font-semibold text-ink">{{ $user->name }}</span></p>

    {{-- Stat row: 2-up on phones, 4-up from lg. --}}
    <div class="mt-4 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        <div class="rounded-2xl border border-gray-200 bg-white px-5 py-4">
            <p class="text-2xl font-bold text-ink">{{ $assignedCount }}</p>
            <p class="mt-0.5 text-sm text-gray-500">Courses assigned</p>
        </div>
        <div class="rounded-2xl border px-5 py-4 {{ $overdueCount > 0 ? 'border-red-100 bg-red-50' : 'border-gray-200 bg-white' }}">
            <p class="text-2xl font-bold {{ $overdueCount > 0 ? 'text-red-600' : 'text-ink' }}">{{ $overdueCount }}</p>
            <p class="mt-0.5 text-sm {{ $overdueCount > 0 ? 'text-red-500' : 'text-gray-500' }}">Overdue</p>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white px-5 py-4">
            <p class="text-2xl font-bold text-ink">{{ $overallProgress }}%</p>
            <p class="mt-0.5 text-sm text-gray-500">Overall progress</p>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white px-5 py-4">
            <p class="text-2xl font-bold text-ink">{{ $certificatesCount }}</p>
            <p class="mt-0.5 text-sm text-gray-500">Certificates earned</p>
        </div>
    </div>

    {{-- Course cards: stacked on phones; on lg, course info + the "why" side by side. --}}
    <div class="mt-6 space-y-4">
        @forelse ($enrollments as $enrollment)
            @php($pill = $this->pill($enrollment))
            @php($pct = $this->progress($enrollment))
            <article class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card lg:flex">
                {{-- Thumbnail: the course image, or a branded placeholder when none is set. --}}
                <div class="h-32 shrink-0 overflow-hidden bg-gray-100 lg:h-auto lg:w-44">
                    @if ($enrollment->course?->thumbnail_url)
                        <img src="{{ $enrollment->course->thumbnail_url }}" alt="{{ $enrollment->course->title }}" class="h-full w-full object-cover">
                    @else
                        <div class="flex h-full w-full items-center justify-center bg-gradient-to-br from-brand to-brand-600">
                            <svg class="h-10 w-10 text-white/70" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/></svg>
                        </div>
                    @endif
                </div>

                {{-- The course. --}}
                <div class="flex-1 p-5 sm:p-6">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px] text-gray-500">
                        <span class="rounded-full px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide {{ $pill['classes'] }}">{{ $pill['label'] }}</span>
                        @if ($enrollment->course?->estimated_minutes)<span>⏱ {{ $enrollment->course->estimated_minutes }} min</span>@endif
                        @if ($enrollment->course)<span>📄 {{ $enrollment->course->lessons_count }} {{ Str::plural('module', $enrollment->course->lessons_count) }}</span>@endif
                        @if ($enrollment->due_at)<span>Due {{ $enrollment->due_at->format('j M') }}</span>@endif
                    </div>

                    <h2 class="mt-3 text-lg font-bold leading-snug tracking-tight text-ink sm:text-xl">{{ $enrollment->course?->title ?? 'Course' }}</h2>

                    <div class="mt-5 flex items-center gap-4">
                        <div class="flex-1">
                            <div class="flex items-center justify-between text-[13px]">
                                <span class="font-medium text-gray-600">{{ $enrollment->status->label() }}</span>
                                <span class="text-gray-400">{{ $pct }}%</span>
                            </div>
                            <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-gray-100">
                                <div class="h-full rounded-full bg-brand" style="width: {{ $pct }}%"></div>
                            </div>
                        </div>
                        @if ($enrollment->status === \App\Enums\EnrollmentStatus::Requested)
                            <span class="rounded-xl bg-gray-100 px-5 py-2.5 text-sm font-semibold text-gray-400">Pending</span>
                        @else
                            <a href="{{ route('portal.course', $enrollment) }}"
                                class="rounded-xl bg-brand px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-600">
                                {{ $enrollment->status === \App\Enums\EnrollmentStatus::Completed ? 'Review' : ($pct > 0 ? 'Continue' : 'Start') }}
                            </a>
                        @endif
                    </div>
                </div>

                {{-- Why you were assigned this (the hero). --}}
                @if ($enrollment->rationale)
                    <div class="border-t border-brand-100 bg-brand-100/40 p-5 sm:p-6 lg:w-80 lg:border-l lg:border-t-0">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-brand">Why you were assigned this</p>
                        <p class="mt-2 text-sm leading-relaxed text-gray-700">{{ $enrollment->rationale }}</p>
                    </div>
                @endif
            </article>
        @empty
            <div class="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-16 text-center">
                <p class="text-[15px] font-semibold text-gray-700">No training assigned yet.</p>
                <p class="mt-1 text-sm text-gray-500">When a performance gap is identified, the courses that address it will show up here — each with the reason it was assigned.</p>
            </div>
        @endforelse
    </div>
</x-portal.shell>
