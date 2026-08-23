<x-portal.shell title="Assessment" :back="route('portal.course', $enrollment)" :back-label="$course?->title ?? 'Course'">
    <div class="mx-auto w-full max-w-3xl">
        @if ($showResult && $lastAttempt)
            {{-- ============ RESULT ============ --}}
            @php($isPass = $passed || $lastAttempt->passed)
            <section class="overflow-hidden rounded-2xl border bg-white shadow-card {{ $isPass ? 'border-green-200' : 'border-gray-200' }}">
                <div class="flex flex-col items-center gap-2 px-6 py-10 text-center {{ $isPass ? 'bg-green-50' : 'bg-gray-50' }}">
                    <span class="flex h-16 w-16 items-center justify-center rounded-full {{ $isPass ? 'bg-green-100 text-green-600' : 'bg-gray-200 text-gray-500' }}">
                        @if ($isPass)
                            <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                        @else
                            <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        @endif
                    </span>
                    <p class="text-[11px] font-bold uppercase tracking-wider {{ $isPass ? 'text-green-600' : 'text-gray-500' }}">{{ $isPass ? 'Passed' : 'Not passed' }}</p>
                    <p class="text-4xl font-bold tracking-tight text-ink">{{ $lastAttempt->score }}%</p>
                    <p class="text-sm text-gray-500">Pass mark {{ $passMark }}%</p>
                </div>

                <div class="px-5 py-6 sm:px-6">
                    @if ($isPass)
                        <p class="text-center text-sm leading-relaxed text-gray-600">
                            You've completed <span class="font-semibold text-ink">{{ $course?->title }}</span>. Your completion has been recorded and your certificate issued.
                        </p>
                        <div class="mt-6 flex flex-wrap justify-center gap-3">
                            <a href="{{ route('portal') }}" class="rounded-xl border border-gray-200 bg-white px-5 py-2.5 text-sm font-semibold text-gray-700 transition hover:bg-gray-50">Back to My Learning</a>
                            @if ($certificate)
                                <a href="{{ route('portal.certificate', $certificate) }}" class="rounded-xl bg-brand px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-600">View certificate</a>
                            @endif
                        </div>
                    @elseif ($attemptsRemaining > 0)
                        <p class="text-center text-sm leading-relaxed text-gray-600">
                            You scored below the pass mark. You have
                            <span class="font-semibold text-ink">{{ $attemptsRemaining }} {{ Str::plural('attempt', $attemptsRemaining) }}</span> left.
                        </p>
                        <div class="mt-6 flex flex-wrap justify-center gap-3">
                            <a href="{{ route('portal.course', $enrollment) }}" class="rounded-xl border border-gray-200 bg-white px-5 py-2.5 text-sm font-semibold text-gray-700 transition hover:bg-gray-50">Review lessons</a>
                            <button wire:click="retry" class="rounded-xl bg-brand px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-600">Try again</button>
                        </div>
                    @else
                        <p class="text-center text-sm leading-relaxed text-gray-600">
                            You've used all {{ $attemptsUsed }} {{ Str::plural('attempt', $attemptsUsed) }} without reaching the pass mark, so this course is marked not passed. Your manager or L&amp;D can re-assign it if you should try again.
                        </p>
                        <div class="mt-6 flex justify-center">
                            <a href="{{ route('portal') }}" class="rounded-xl border border-gray-200 bg-white px-5 py-2.5 text-sm font-semibold text-gray-700 transition hover:bg-gray-50">Back to My Learning</a>
                        </div>
                    @endif
                </div>
            </section>
        @else
            {{-- ============ THE QUIZ ============ --}}
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-xl font-bold tracking-tight text-ink">{{ $quiz->title }}</h2>
                    <p class="mt-0.5 text-sm text-gray-500">Pass mark {{ $passMark }}% · {{ $questions->count() }} {{ Str::plural('question', $questions->count()) }}</p>
                </div>
                <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600">
                    {{ $attemptsRemaining }} {{ Str::plural('attempt', $attemptsRemaining) }} left
                </span>
            </div>

            <form wire:submit="submit" class="mt-6 space-y-4">
                @foreach ($questions as $i => $question)
                    <fieldset class="rounded-2xl border border-gray-200 bg-white p-5 shadow-card sm:p-6">
                        <legend class="sr-only">Question {{ $i + 1 }}</legend>
                        <p class="flex gap-2 text-[15px] font-semibold text-ink">
                            <span class="text-gray-400">{{ $i + 1 }}.</span>
                            <span>{{ $question->prompt }}</span>
                        </p>

                        <div class="mt-4 space-y-2">
                            @foreach ($question->options as $option)
                                @php($isMultiple = $question->type === \App\Enums\QuestionType::Multiple)
                                <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-gray-200 px-4 py-3 text-sm text-gray-700 transition hover:bg-gray-50 has-[:checked]:border-brand has-[:checked]:bg-brand-100/40">
                                    <input
                                        type="{{ $isMultiple ? 'checkbox' : 'radio' }}"
                                        wire:model="answers.{{ $question->id }}"
                                        value="{{ $option['key'] }}"
                                        class="h-4 w-4 shrink-0 accent-brand"
                                    >
                                    <span>{{ $option['label'] }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endforeach

                <div class="flex items-center justify-end gap-3 pt-2">
                    <a href="{{ route('portal.course', $enrollment) }}" class="text-sm font-medium text-gray-500 hover:text-gray-800">Cancel</a>
                    <button type="submit"
                        class="rounded-xl bg-brand px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-600"
                        wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="submit">Submit assessment</span>
                        <span wire:loading wire:target="submit">Grading…</span>
                    </button>
                </div>
            </form>
        @endif
    </div>
</x-portal.shell>
