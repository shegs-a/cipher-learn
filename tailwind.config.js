import forms from '@tailwindcss/forms';
import defaultTheme from 'tailwindcss/defaultTheme';

/**
 * CipherLearn design tokens.
 *
 * Extracted from the Claude Design canvas exports in /design (see the admin and
 * learner .html files). These are the single source of truth for brand colour,
 * typography and shape across the Livewire/Blade UI. Filament's own panel theme
 * is configured separately (Sprint 1) but points at the same `brand` primary so
 * the admin console and learner app stay visually consistent.
 *
 * @type {import('tailwindcss').Config}
 */
export default {
    content: [
        './app/**/*.php',
        './resources/**/*.blade.php',
        './resources/**/*.js',
        // Livewire/Filament component views are picked up here once those
        // packages are installed in Sprint 1.
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
    ],
    theme: {
        extend: {
            fontFamily: {
                // `Inter` first, then the platform sans stack as a graceful fallback
                // before the webfont loads. Weights 300–700 are loaded in the layout.
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                // Primary brand (indigo). `DEFAULT` (#4f46e5) is the primary; the
                // ramp follows Tailwind's tuned indigo scale for hovers, fills and
                // tints across the UI.
                brand: {
                    50: '#eef2ff',
                    100: '#e0e7ff',
                    200: '#c7d2fe',
                    300: '#a5b4fc',
                    400: '#818cf8',
                    500: '#6366f1',
                    600: '#4f46e5',
                    700: '#4338ca',
                    800: '#3730a3',
                    900: '#312e81',
                    DEFAULT: '#4f46e5',
                    hover: '#4338ca',
                },
                // Danger / overdue. Used for overdue enrolments and destructive states.
                danger: {
                    50: '#fef3f2',
                    100: '#fbd5d1',
                    600: '#b42318',
                    700: '#912015',
                    DEFAULT: '#b42318',
                },
                // Success — completed / on-track.
                success: {
                    50: '#eef7f1',
                    100: '#c7e6d3',
                    600: '#1f7a4d',
                    700: '#1f4d34',
                    DEFAULT: '#1f7a4d',
                },
                // Warning / amber — e.g. "HRIS endpoint not available yet".
                warning: {
                    50: '#fdf6e9',
                    100: '#f5e2ba',
                    600: '#d99a1c',
                    700: '#92610a',
                    DEFAULT: '#d99a1c',
                },
                // Accent — recommender / AI-sourced badges.
                accent: {
                    50: '#f3edfb',
                    100: '#e0d1f7',
                    600: '#6d28d9',
                    DEFAULT: '#6d28d9',
                },
                // App chrome neutrals used alongside the default Tailwind gray ramp.
                canvas: '#eef0f3', // page background
                ink: '#1f2733', // strongest text / wordmark
            },
            borderRadius: {
                card: '12px',
                panel: '14px',
            },
            boxShadow: {
                card: '0 1px 3px rgba(16, 24, 40, 0.05)',
            },
        },
    },
    plugins: [forms],
};
