<x-portal.shell :title="$course?->title ?? 'Course'" :back="route('portal')" back-label="My Learning">
    <div class="mx-auto w-full max-w-5xl">
        {{-- Progress summary + the reason this was assigned (the product hero). --}}
        <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-card sm:p-6">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px] text-gray-500">
                <span class="rounded-full bg-brand-100 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-brand">{{ $enrollment->status->label() }}</span>
                @if ($course?->estimated_minutes)<span>⏱ {{ $course->estimated_minutes }} min</span>@endif
                <span>📄 {{ $lessons->count() }} {{ Str::plural('module', $lessons->count()) }}</span>
                @if ($enrollment->due_at)<span>Due {{ $enrollment->due_at->format('j M') }}</span>@endif
            </div>

            <div class="mt-4 flex items-center gap-4">
                <div class="flex-1">
                    <div class="flex items-center justify-between text-[13px]">
                        <span class="font-medium text-gray-600">{{ $completedIds->count() }} of {{ $lessons->count() }} lessons complete</span>
                        <span class="text-gray-400">{{ $percent }}%</span>
                    </div>
                    <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-gray-100">
                        <div class="h-full rounded-full bg-brand transition-all" style="width: {{ $percent }}%"></div>
                    </div>
                </div>
            </div>

            @if ($enrollment->rationale)
                <div class="mt-5 rounded-xl border border-brand-100 bg-brand-100/40 p-4">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-brand">Why you were assigned this</p>
                    <p class="mt-1.5 text-sm leading-relaxed text-gray-700">{{ $enrollment->rationale }}</p>
                </div>
            @endif
        </section>

        @if ($currentLesson)
            {{-- Reader: one lesson's content, then "mark complete". --}}
            <article class="mt-6 rounded-2xl border border-gray-200 bg-white p-5 shadow-card sm:p-6 lg:p-8">
                <div class="flex items-center justify-between gap-4">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-gray-400">
                        Lesson {{ $lessons->search(fn ($l) => $l->id === $currentLesson->id) + 1 }} of {{ $lessons->count() }}
                    </p>
                    <button wire:click="backToOutline" class="text-sm font-medium text-gray-500 hover:text-gray-800">Back to outline</button>
                </div>
                <h2 class="mt-2 text-xl font-bold tracking-tight text-ink sm:text-2xl">{{ $currentLesson->title }}</h2>
                @if ($currentLesson->file_size_bytes)
                    <p class="mt-1 text-xs text-gray-400">≈ {{ number_format($currentLesson->file_size_bytes / 1024, 0) }} KB @if ($currentLesson->estimated_minutes)· {{ $currentLesson->estimated_minutes }} min @endif</p>
                @endif

                <div class="prose prose-sm mt-5 max-w-none leading-relaxed text-gray-700">
                    @if (filled($currentLesson->content))
                        {!! nl2br(e($currentLesson->content)) !!}
                    @else
                        <p class="text-gray-400">This lesson's material will appear here.</p>
                    @endif
                </div>

                <div class="mt-8 flex flex-wrap items-center justify-end gap-3 border-t border-gray-100 pt-5">
                    @if ($completedIds->contains($currentLesson->id))
                        <span class="inline-flex items-center gap-1.5 text-sm font-medium text-green-600">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                            Completed
                        </span>
                    @endif
                    <button wire:click="complete('{{ $currentLesson->id }}')"
                        class="rounded-xl bg-brand px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-600">
                        {{ $completedIds->contains($currentLesson->id) ? 'Next lesson' : 'Mark complete & continue' }}
                    </button>
                </div>
            </article>
        @else
            {{-- Outline: the lesson list with completion ticks. --}}
            <section class="mt-6 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card">
                <h2 class="border-b border-gray-100 px-5 py-4 text-sm font-bold uppercase tracking-wider text-gray-400 sm:px-6">Course outline</h2>
                <ul class="divide-y divide-gray-100">
                    @forelse ($lessons as $i => $lesson)
                        @php($done = $completedIds->contains($lesson->id))
                        <li>
                            <button wire:click="openLesson('{{ $lesson->id }}')" class="flex w-full items-center gap-4 px-5 py-4 text-left hover:bg-gray-50 sm:px-6">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-bold {{ $done ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500' }}">
                                    @if ($done)
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                    @else
                                        {{ $i + 1 }}
                                    @endif
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold text-ink">{{ $lesson->title }}</span>
                                    @if ($lesson->estimated_minutes)<span class="text-xs text-gray-400">{{ $lesson->estimated_minutes }} min</span>@endif
                                </span>
                                <svg class="h-4 w-4 shrink-0 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                            </button>
                        </li>
                    @empty
                        <li class="px-6 py-10 text-center text-sm text-gray-400">This course has no lessons yet.</li>
                    @endforelse
                </ul>

                {{-- Quiz gate: unlocked once every lesson is complete. --}}
                <div class="border-t border-gray-100 bg-gray-50 px-5 py-5 sm:px-6">
                    @if ($course?->quiz)
                        @if ($allLessonsComplete)
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                                <div>
                                    <p class="text-sm font-semibold text-ink">Ready for the assessment</p>
                                    <p class="text-xs text-gray-500">Pass to complete the course and earn your certificate.</p>
                                </div>
                                <a href="{{ route('portal.course.quiz', $enrollment) }}"
                                    class="rounded-xl bg-brand px-5 py-2.5 text-center text-sm font-semibold text-white transition hover:bg-brand-600">
                                    Take assessment
                                </a>
                            </div>
                        @else
                            <div class="flex items-center gap-3 text-sm text-gray-500">
                                <svg class="h-5 w-5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 0h10.5a2.25 2.25 0 012.25 2.25v6.75a2.25 2.25 0 01-2.25 2.25H6.75a2.25 2.25 0 01-2.25-2.25v-6.75a2.25 2.25 0 012.25-2.25z"/></svg>
                                Complete every lesson to unlock the assessment.
                            </div>
                        @endif
                    @endif
                </div>
            </section>
        @endif
    </div>
</x-portal.shell>
