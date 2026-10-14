/// <reference types="vitest" />

import legacy from '@vitejs/plugin-legacy'
import vue from '@vitejs/plugin-vue'
import path from 'path'
import { defineConfig } from 'vite'

/**
 * Dev server: what Symfony serves (API, media, server pages, email links)
 * is relayed to the backend, so the browser sees ONE origin, as in
 * production — the web session cookie (spec §11 décision 56) needs it.
 * BACKEND_URL overrides the target.
 */
const backend = process.env.BACKEND_URL ?? 'http://localhost:8000'
const relayed = ['/api', '/media', '/privacy', '/exports', '/account/cancel-deletion', '/unsubscribe', '/.well-known', '^/u/[^/]+/media/']

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    legacy()
  ],
  server: {
    proxy: Object.fromEntries(relayed.map((path) => [path, { target: backend }])),
  },
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  test: {
    globals: true,
    environment: 'jsdom'
  }
})
