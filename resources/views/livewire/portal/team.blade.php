<x-portal.shell title="My team">
    <div class="mx-auto w-full max-w-4xl">
        <p class="text-[15px] text-gray-500">Assign a course to a report — with the reason it matters, which they'll see.</p>

        <div
            x-data="{ shown: false }"
            x-on:course-assigned.window="shown = true; setTimeout(() => shown = false, 4000)"
            x-show="shown" x-cloak x-transition
            class="mt-4 rounded-xl bg-green-50 px-4 py-3 text-sm font-medium text-green-700">
            Course assigned — your report will see it with the reason you gave.
        </div>

        <div class="mt-6 space-y-4">
            @forelse ($reports as $report)
                @php($open = $report->enrollments->whereIn('status', $openStatuses))
                <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-card">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                        <div>
                            <h2 class="text-lg font-bold tracking-tight text-ink">{{ $report->full_name }}</h2>
                            <p class="text-sm text-gray-500">{{ trim(($report->job_title ? $report->job_title : '').($report->department ? ' · '.$report->department : '')) ?: 'Team member' }}</p>
                        </div>
                        <button wire:click="startAssign('{{ $report->id }}')"
                            class="shrink-0 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-600">
                            Assign a course
                        </button>
                    </div>

                    <div class="mt-4 space-y-2">
                        @forelse ($open as $enrollment)
                            <div class="flex items-center justify-between gap-3 rounded-xl border border-gray-100 bg-gray-50 px-4 py-2.5">
                                <span class="min-w-0 truncate text-sm font-medium text-gray-800">{{ $enrollment->course?->title ?? 'Course' }}</span>
                                <span class="shrink-0 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $enrollment->status->label() }}</span>
                            </div>
                        @empty
                            <p class="text-sm text-gray-400">No open courses assigned.</p>
                        @endforelse
                    </div>
                </article>
            @empty
                <div class="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-12 text-center">
                    <p class="text-[15px] font-semibold text-gray-700">You don't manage anyone yet.</p>
                    <p class="mt-1 text-sm text-gray-500">When employees report to you in the HR system, they'll appear here.</p>
                </div>
            @endforelse
        </div>
    </div>

    {{-- Assign modal --}}
    @if ($assigningToEmployeeId)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
                <h3 class="text-lg font-bold text-ink">Assign a course</h3>

                <label class="mt-4 block text-sm font-semibold text-gray-700">Course</label>
                <select wire:model="courseId" class="mt-1.5 block w-full rounded-xl border border-gray-300 px-3 py-2 text-[15px] text-ink shadow-sm focus:border-brand focus:ring-brand">
                    <option value="">Select a course…</option>
                    @foreach ($courses as $course)
                        <option value="{{ $course->id }}">{{ $course->title }}</option>
                    @endforeach
                </select>
                @error('courseId') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror

                <label class="mt-4 block text-sm font-semibold text-gray-700">Why this course?</label>
                <textarea wire:model="reason" rows="3"
                    class="mt-1.5 block w-full rounded-xl border border-gray-300 px-3 py-2 text-[15px] text-ink shadow-sm focus:border-brand focus:ring-brand"
                    placeholder="e.g. Deal-conversion flagged as a growth area in your Q2 review"></textarea>
                @error('reason') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror

                <label class="mt-4 block text-sm font-semibold text-gray-700">Due date <span class="font-normal text-gray-400">(optional)</span></label>
                <input type="date" wire:model="dueDate" class="mt-1.5 block w-full rounded-xl border border-gray-300 px-3 py-2 text-[15px] text-ink shadow-sm focus:border-brand focus:ring-brand">

                <div class="mt-6 flex justify-end gap-3">
                    <button wire:click="cancelAssign" class="rounded-lg px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50">Cancel</button>
                    <button wire:click="submitAssign" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600">Assign</button>
                </div>
            </div>
        </div>
    @endif
</x-portal.shell>
