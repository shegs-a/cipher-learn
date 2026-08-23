<x-portal.shell title="Learning paths">
    <div class="mx-auto w-full max-w-4xl">
        {{-- ===== My paths ===== --}}
        <h2 class="text-sm font-bold uppercase tracking-wider text-gray-400">Your paths</h2>
        <div class="mt-3 space-y-4">
            @forelse ($myPaths as $membership)
                @php($path = $membership->learningPath)
                @php($pct = $membership->completionPercent())
                <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-card sm:p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="text-lg font-bold tracking-tight text-ink">{{ $path->name }}</h3>
                            @if ($path->description)<p class="mt-1 text-sm text-gray-500">{{ $path->description }}</p>@endif
                        </div>
                        <span class="shrink-0 rounded-full bg-brand-100 px-3 py-1 text-xs font-bold text-brand">{{ $pct }}%</span>
                    </div>

                    <div class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-gray-100">
                        <div class="h-full rounded-full bg-brand transition-all" style="width: {{ $pct }}%"></div>
                    </div>

                    <ul class="mt-5 divide-y divide-gray-100 border-t border-gray-100">
                        @foreach ($path->courses as $i => $course)
                            @php($enrolment = $enrolmentsByCourse->get($course->id))
                            @php($done = $enrolment && $enrolment->status === \App\Enums\EnrollmentStatus::Completed)
                            <li class="flex items-center gap-3 py-3">
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold {{ $done ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500' }}">
                                    @if ($done)
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                    @else
                                        {{ $i + 1 }}
                                    @endif
                                </span>
                                <span class="min-w-0 flex-1 truncate text-sm font-medium text-ink">{{ $course->title }}</span>
                                @if ($enrolment)
                                    <a href="{{ route('portal.course', $enrolment) }}" class="shrink-0 text-sm font-semibold text-brand hover:underline">
                                        {{ $done ? 'Review' : 'Open' }}
                                    </a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </article>
            @empty
                <div class="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-12 text-center">
                    <p class="text-[15px] font-semibold text-gray-700">You're not on any learning paths yet.</p>
                    <p class="mt-1 text-sm text-gray-500">Join one below to get a ready-made curriculum added to your learning.</p>
                </div>
            @endforelse
        </div>

        {{-- ===== Available paths ===== --}}
        @if ($available->isNotEmpty())
            <h2 class="mt-10 text-sm font-bold uppercase tracking-wider text-gray-400">Available paths</h2>
            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                @foreach ($available as $path)
                    <article class="flex flex-col rounded-2xl border border-gray-200 bg-white p-5 shadow-card sm:p-6">
                        <h3 class="text-[15px] font-bold text-ink">{{ $path->name }}</h3>
                        @if ($path->description)<p class="mt-1 flex-1 text-sm text-gray-500">{{ $path->description }}</p>@endif
                        <p class="mt-3 text-xs text-gray-400">{{ $path->courses->count() }} {{ Str::plural('course', $path->courses->count()) }}</p>
                        <button wire:click="enrol('{{ $path->id }}')" wire:loading.attr="disabled"
                            class="mt-4 rounded-xl bg-brand px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-600">
                            Enrol
                        </button>
                    </article>
                @endforeach
            </div>
        @endif
    </div>
</x-portal.shell>
