/// <reference types="vitest" />

import legacy from '@vitejs/plugin-legacy'
import vue from '@vitejs/plugin-vue'
import path from 'path'
import { defineConfig, loadEnv, type Plugin } from 'vite'

/**
 * Dev server: what Symfony serves (API, media, server pages, email links)
 * is relayed to the backend, so the browser sees ONE origin, as in
 * production — the web session cookie (spec §11 décision 56) needs it.
 * BACKEND_URL overrides the target.
 */
const backend = process.env.BACKEND_URL ?? 'http://localhost:8000'
const relayed = ['/api', '/media', '/privacy', '/exports', '/account/cancel-deletion', '/unsubscribe', '/.well-known', '^/u/[^/]+/media/']

/**
 * The web app manifest (icons: spec §11 décision 61), named from
 * VITE_APP_NAME rather than written by hand (CLAUDE.md rule 8).
 */
function webManifest(appName: string): Plugin {
  const color = '#2A1245'
  const icon = (size: number, purpose: 'any' | 'maskable') => ({
    src: `/icon-${purpose === 'maskable' ? 'maskable-' : ''}${size}.png`,
    sizes: `${size}x${size}`,
    type: 'image/png',
    purpose,
  })
  const manifest = JSON.stringify({
    name: appName,
    short_name: appName,
    start_url: '/',
    icons: [icon(192, 'any'), icon(512, 'any'), icon(192, 'maskable'), icon(512, 'maskable')],
    theme_color: color,
    background_color: color,
    display: 'standalone',
  }, null, 2)
  return {
    name: 'web-manifest',
    configureServer(server) {
      server.middlewares.use('/site.webmanifest', (_req, res) => {
        res.setHeader('Content-Type', 'application/manifest+json')
        res.end(manifest)
      })
    },
    generateBundle() {
      this.emitFile({ type: 'asset', fileName: 'site.webmanifest', source: manifest })
    },
  }
}

// https://vitejs.dev/config/
export default defineConfig(({ mode }) => ({
  plugins: [
    vue(),
    legacy(),
    webManifest(loadEnv(mode, process.cwd()).VITE_APP_NAME ?? ''),
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
}))
