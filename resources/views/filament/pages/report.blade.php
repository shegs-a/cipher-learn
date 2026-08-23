<x-filament-panels::page>
    {{-- Filters — applied only when the Filter button is pressed. --}}
    <form wire:submit="applyFilters" class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        {{ $this->form }}

        {{-- Action row: separated from the filter fields with a divider + spacing. --}}
        <div class="mt-6 flex items-center gap-3 border-t border-gray-100 pt-4 dark:border-white/10">
            <x-filament::button type="submit" icon="heroicon-m-funnel">
                Filter
            </x-filament::button>
            <x-filament::button type="button" color="gray" wire:click="resetFilters">
                Reset
            </x-filament::button>
        </div>
    </form>

    {{-- Results (same tenant-scoped query as the CSV export). Updates in place when
         Filter is pressed — no full-page reload. --}}
    {{ $this->table }}
</x-filament-panels::page>
