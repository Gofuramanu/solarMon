/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/**/*.blade.php',
        './resources/**/*.js',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['"Plus Jakarta Sans"', '"Segoe UI"', 'system-ui', '-apple-system', 'sans-serif'],
            },
            colors: {
                canvas: '#F8F9FF',
                slateink: '#444653',
                cobalt: {
                    50: '#EFF4FF',
                    100: '#D3E4FE',
                    200: '#BFDBFE',
                    600: '#2563EB',
                    700: '#1D4ED8',
                    800: '#1E40AF',
                    900: '#00288E',
                    950: '#172554',
                },
                navy: {
                    900: '#0F172A',
                    800: '#1E293B',
                    700: '#334155',
                },
                solar: {
                    50: '#FFFBEB',
                    100: '#FEF3C7',
                    500: '#F59E0B',
                    600: '#92400E',
                },
                electric: {
                    50: '#EFF4FF',
                    100: '#D3E4FE',
                    500: '#2563EB',
                    600: '#1E40AF',
                },
            },
        },
    },
    plugins: [],
};
