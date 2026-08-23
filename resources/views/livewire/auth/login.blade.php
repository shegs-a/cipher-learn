{{-- NOTE: no x-data on this root <div> — it is the Livewire component root, and
     Alpine state there breaks Livewire's directive binding (wire:submit silently
     falls back to a native form submit). The password-reveal state lives on the
     <form> instead. --}}
<div class="flex min-h-screen">
    {{-- Left: brand value-proposition panel (the product's whole thesis). --}}
    <div class="relative hidden w-1/2 flex-col justify-center overflow-hidden bg-brand px-14 lg:flex xl:px-20">
        <div class="relative z-10 max-w-md">
            <p class="text-[13px] font-bold uppercase tracking-widest text-white/70">The LMS that tells you why</p>
            <h2 class="mt-5 text-4xl font-bold leading-tight tracking-tight text-white xl:text-[42px]">
                Every course explains the appraisal gap it was assigned to close.
            </h2>
            <p class="mt-5 text-lg leading-relaxed text-white/80">
                Learning that reads your performance record — so development always has a reason.
            </p>
            <div class="mt-10 flex items-center gap-2.5 text-white/90">
                <span class="text-[13px] font-medium uppercase tracking-wider text-white/60">Powered by</span>
                <span class="flex h-6 w-6 items-center justify-center rounded-[7px] bg-white/15">
                    <svg class="h-4 w-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/></svg>
                </span>
                <span class="text-[15px] font-bold tracking-tight text-white">CipherLearn</span>
            </div>
        </div>
        {{-- Subtle geometric accent. --}}
        <div class="pointer-events-none absolute -right-24 -top-24 h-96 w-96 rounded-full bg-white/5"></div>
        <div class="pointer-events-none absolute -bottom-32 -left-16 h-80 w-80 rounded-full bg-white/5"></div>
    </div>

    {{-- Right: the sign-in card. --}}
    <div class="flex w-full items-center justify-center bg-white px-6 py-12 lg:w-1/2">
        <div class="w-full max-w-[380px]">
            {{-- Wordmark (also the brand for mobile, where the left panel is hidden). --}}
            <div class="flex items-center gap-2.5 lg:hidden">
                <span class="flex h-8 w-8 items-center justify-center rounded-[9px] bg-brand">
                    <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/></svg>
                </span>
                <span class="text-lg font-bold tracking-tight text-ink">CipherLearn</span>
            </div>

            <h1 class="mt-8 text-3xl font-bold tracking-tight text-ink lg:mt-0">Sign in to your account</h1>
            <p class="mt-2 text-[15px] leading-relaxed text-gray-500">
                One login for learning, creating, and managing.
            </p>

            <form wire:submit="authenticate" class="mt-8 space-y-5" x-data="{ show: false }">
                <div>
                    <label for="email" class="block text-sm font-semibold text-gray-700">Work email</label>
                    <input
                        wire:model="email"
                        id="email" type="email" autocomplete="email" required autofocus
                        placeholder="you@company.com"
                        class="mt-2 block w-full rounded-xl border border-gray-300 px-4 py-3 text-[15px] text-ink placeholder-gray-400 shadow-sm focus:border-brand focus:ring-brand"
                    >
                    @error('email')
                        <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <div class="flex items-center justify-between">
                        <label for="password" class="block text-sm font-semibold text-gray-700">Password</label>
                        <button type="button" wire:click="$dispatch('notify-todo')" class="text-sm font-semibold text-brand hover:text-brand-600">
                            Forgot?
                        </button>
                    </div>
                    <div class="relative mt-2">
                        <input
                            wire:model="password"
                            id="password" :type="show ? 'text' : 'password'" autocomplete="current-password" required
                            class="block w-full rounded-xl border border-gray-300 px-4 py-3 pr-11 text-[15px] text-ink shadow-sm focus:border-brand focus:ring-brand"
                        >
                        <button type="button" @click="show = !show" tabindex="-1"
                            class="absolute inset-y-0 right-0 flex items-center pr-3.5 text-gray-400 hover:text-gray-600"
                            :aria-label="show ? 'Hide password' : 'Show password'">
                            <svg x-show="!show" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            <svg x-show="show" x-cloak xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.243 4.243L9.88 9.88"/></svg>
                        </button>
                    </div>
                    @error('password')
                        <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <button
                    type="submit"
                    class="flex w-full items-center justify-center rounded-xl bg-brand px-4 py-3.5 text-[15px] font-bold text-white shadow-sm transition hover:bg-brand-600 focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-2"
                    wire:loading.attr="disabled" wire:target="authenticate"
                >
                    <span wire:loading.remove wire:target="authenticate">Sign in</span>
                    <span wire:loading wire:target="authenticate">Signing in…</span>
                </button>
            </form>

            <div class="my-6 flex items-center gap-4">
                <span class="h-px flex-1 bg-gray-200"></span>
                <span class="text-sm text-gray-400">or</span>
                <span class="h-px flex-1 bg-gray-200"></span>
            </div>

            <button type="button" wire:click="$dispatch('notify-todo')"
                class="flex w-full items-center justify-center gap-2 rounded-xl border border-gray-300 px-4 py-3 text-[15px] font-semibold text-gray-700 transition hover:bg-gray-50">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                Continue with company SSO
            </button>

            <p class="mt-8 text-center text-[13px] leading-relaxed text-gray-400">
                Access is provisioned through your HR record.
            </p>
        </div>
    </div>

    {{-- Info toast for the not-yet-built paths (SSO, password reset). --}}
    <div x-data="{ show: false, msg: '' }"
         x-on:notify-todo.window="msg = 'That isn’t enabled for your organisation yet — contact your administrator.'; show = true; setTimeout(() => show = false, 4000)"
         x-show="show" x-cloak x-transition
         class="fixed inset-x-4 bottom-6 mx-auto max-w-sm rounded-xl bg-ink px-4 py-3 text-center text-sm text-white shadow-lg">
        <span x-text="msg"></span>
    </div>
</div>
