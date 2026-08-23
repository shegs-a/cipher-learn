<div class="relative" x-data="{ open: false }">
    {{-- Opening the bell marks unread notifications as read (matches the previous
         dashboard behaviour), now consistent on every portal page. --}}
    <button type="button" @click="open = !open; if (open) { $wire.markRead() }"
        class="relative flex h-10 w-10 items-center justify-center rounded-xl border border-gray-200 bg-white text-gray-600 transition hover:bg-gray-50">
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0"/></svg>
        @if ($unread > 0)
            <span class="absolute -right-1 -top-1 flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-red-500 px-1 text-[11px] font-bold text-white">{{ $unread > 9 ? '9+' : $unread }}</span>
        @endif
    </button>

    <div x-show="open" x-cloak @click.outside="open = false" x-transition
        class="absolute right-0 z-40 mt-2 w-80 max-w-[calc(100vw-2rem)] overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-lg">
        <p class="border-b border-gray-100 px-4 py-3 text-sm font-bold text-ink">Notifications</p>
        <div class="max-h-96 overflow-y-auto">
            @forelse ($notifications as $note)
                <a href="{{ $note->data['url'] ?? route('portal') }}"
                    class="flex flex-col gap-0.5 border-b border-gray-50 px-4 py-3 hover:bg-gray-50 {{ $note->read_at === null ? 'bg-brand-100/30' : '' }}">
                    <span class="text-sm font-semibold text-ink">{{ $note->data['title'] ?? 'Notification' }}</span>
                    <span class="text-[13px] text-gray-600">{{ $note->data['body'] ?? '' }}</span>
                    <span class="text-[11px] text-gray-400">{{ $note->created_at->diffForHumans() }}</span>
                </a>
            @empty
                <p class="px-4 py-10 text-center text-sm text-gray-400">You're all caught up.</p>
            @endforelse
        </div>
    </div>
</div>
