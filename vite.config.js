import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.jsx'],
            refresh: true,
        }),
        react({
            jsxRuntime: 'automatic',
        }),
        tailwindcss(),
    ],
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        hmr: {
            host: '192.168.100.21',
        },
        cors: true,
        watch: {
            // Reduce memory by ignoring unnecessary folders
            ignored: ['**/node_modules/**', '**/vendor/**', '**/storage/**', '**/.git/**'],
            usePolling: false,
        },
    },
    optimizeDeps: {
        include: ['react', 'react-dom', 'react-router-dom'],
    },
    // Reduce memory usage
    build: {
        sourcemap: false,
    },
    cacheDir: '.vite',
});
