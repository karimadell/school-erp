import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', 'Figtree', ...defaultTheme.fontFamily.sans],
            },
        },
    },

    // Modern UI v2: the dashboard shell/pages coexist on the same request
    // with 70+ legacy Bootstrap-based views (resources/views/dashboard/*)
    // that this batch does not touch. Tailwind's Preflight is a global CSS
    // reset (margins, list-style, form-control appearance, etc.) that would
    // fight with Bootstrap's own Reboot reset on those untouched pages.
    // Disabling it means Tailwind utility classes still work everywhere
    // they're explicitly used, without silently restyling legacy markup
    // that never opted in.
    corePlugins: {
        preflight: false,
    },

    plugins: [forms],
};
