/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                mono: ['"JetBrains Mono"', 'ui-monospace', 'monospace'],
            },
            colors: {
                navy: {
                    50: '#f1f4f9',
                    100: '#dfe6f0',
                    200: '#c3cfe2',
                    300: '#97abcb',
                    400: '#6682ae',
                    500: '#3f5f91',
                    600: '#1f3f73',
                    700: '#183462',
                    800: '#132a50',
                    900: '#0f2140',
                },
            },
        },
    },
    plugins: [],
};
