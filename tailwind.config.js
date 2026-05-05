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
                sans: ['DM Sans', ...defaultTheme.fontFamily.sans],
                mono: ['DM Mono', ...defaultTheme.fontFamily.mono],
            },

            colors: {
                app: {
                    bg:            '#eef1f6',
                    card:          '#f5f7fb',
                    elevated:      '#ffffff',
                    border:        '#dde2ec',
                    'border-soft': '#e8ecf4',
                    hover:         '#eef1f6',
                },
                sidebar: {
                    bg:             '#0a0d12',
                    border:         '#1a2030',
                    panel:          '#1d2433',
                    'panel-border': '#2a3347',
                },
                ink: {
                    primary:         '#1a2235',
                    secondary:       '#5a6682',
                    muted:           '#9aa3b8',
                    'on-dark':       '#e8ecf5',
                    'muted-on-dark': '#8b95ad',
                },
                accent: {
                    DEFAULT:       '#f5b731',
                    hover:         '#fbc84a',
                    pressed:       '#c49010',
                    soft:          '#fef9eb',
                    'soft-strong': '#fef3d0',
                    'soft-border': '#f0d070',
                },
                ok:      { DEFAULT: '#1a7a48', soft: '#d1f5e4', border: '#a8e8c8' },
                danger:  { DEFAULT: '#c0391a', soft: '#fde8e2', border: '#f5c0b0' },
                warn:    { DEFAULT: '#a07010', soft: '#fef3d0', border: '#f0d070' },
                neutral: { DEFAULT: '#5a6682', soft: '#e8ecf5', border: '#c4cad8' },
            },

            keyframes: {
                pulseAmber: {
                    '0%,100%': { opacity: '1' },
                    '50%':     { opacity: '0.45' },
                },
                fadeInPage: {
                    from: { opacity: '0', transform: 'translateY(4px)' },
                    to:   { opacity: '1', transform: 'translateY(0)'  },
                },
            },
            animation: {
                'pulse-amber': 'pulseAmber 1.5s ease-in-out infinite',
                'fade-in':     'fadeInPage 0.18s ease-out',
            },
        },
    },

    plugins: [forms],
};
