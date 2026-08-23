<x-portal.shell title="Course catalogue">
    <div class="mx-auto w-full max-w-4xl">
        <p class="text-[15px] text-gray-500">Browse every course available to your organisation. Ask to take anything that would help you.</p>

        <div
            x-data="{ shown: false }"
            x-on:request-sent.window="shown = true; setTimeout(() => shown = false, 4000)"
            x-show="shown" x-cloak x-transition
            class="mt-4 rounded-xl bg-green-50 px-4 py-3 text-sm font-medium text-green-700">
            Request sent — a manager or admin will review it.
        </div>

        <div class="mt-6 grid gap-4 sm:grid-cols-2">
            @forelse ($courses as $course)
                @php($enrolled = in_array($course->id, $enrolledCourseIds, true))
                <article class="flex flex-col rounded-2xl border border-gray-200 bg-white p-5 shadow-card">
                    <h2 class="text-lg font-bold leading-snug tracking-tight text-ink">{{ $course->title }}</h2>
                    @if ($course->summary)
                        <p class="mt-1.5 line-clamp-2 text-sm text-gray-500">{{ $course->summary }}</p>
                    @endif
                    <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-[13px] text-gray-500">
                        @if ($course->estimated_minutes)<span>⏱ {{ $course->estimated_minutes }} min</span>@endif
                        <span>📄 {{ $course->lessons_count }} {{ Str::plural('module', $course->lessons_count) }}</span>
                    </div>

                    <div class="mt-4 flex-1"></div>
                    <div class="mt-4">
                        @if ($enrolled)
                            <span class="inline-flex items-center rounded-lg bg-gray-100 px-3 py-2 text-sm font-semibold text-gray-500">On your list</span>
                        @else
                            <button wire:click="startRequest('{{ $course->id }}')"
                                class="rounded-lg border border-brand px-4 py-2 text-sm font-semibold text-brand transition hover:bg-brand-100">
                                Request access
                            </button>
                        @endif
                    </div>
                </article>
            @empty
                <div class="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-12 text-center sm:col-span-2">
                    <p class="text-[15px] font-semibold text-gray-700">No courses published yet.</p>
                </div>
            @endforelse
        </div>
    </div>

    {{-- Request modal --}}
    @if ($requestingCourseId)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
                <h3 class="text-lg font-bold text-ink">Request access</h3>
                <p class="mt-1 text-sm text-gray-500">Tell us briefly why you'd like to take this course. A manager or admin will review it.</p>
                <textarea wire:model="note" rows="3"
                    class="mt-4 block w-full rounded-xl border border-gray-300 px-3 py-2 text-[15px] text-ink shadow-sm focus:border-brand focus:ring-brand"
                    placeholder="e.g. Relevant to a project I'm about to start"></textarea>
                @error('note') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror

                <div class="mt-5 flex justify-end gap-3">
                    <button wire:click="cancelRequest" class="rounded-lg px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50">Cancel</button>
                    <button wire:click="submitRequest" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600">Send request</button>
                </div>
            </div>
        </div>
    @endif
</x-portal.shell>
