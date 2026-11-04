import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
export default defineConfig({ plugins: [react()], publicDir: false, build: { outDir: 'public', emptyOutDir: false, rollupOptions: { input: 'app.html' } }, server: { proxy: { '/api': 'http://127.0.0.1:8080', '/files': 'http://127.0.0.1:8080', '/brand': 'http://127.0.0.1:8080' } } });
