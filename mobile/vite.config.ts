/// <reference types="vitest" />

import legacy from '@vitejs/plugin-legacy'
import vue from '@vitejs/plugin-vue'
import { execSync } from 'child_process'
import { readFileSync } from 'fs'
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
    // Installed from Chrome on Android, the app is offered in the share
    // sheet (spec §5.6) and opens /share?url=…&text=…&title=…, the route
    // the native share intent already uses.
    share_target: {
      action: '/share',
      method: 'GET',
      params: { title: 'title', text: 'text', url: 'url' },
    },
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

/**
 * Shown in Profil: package.json's version, and the commit it was built
 * from — APP_BUILD (the production image has no .git: deploy-prod.sh
 * passes it), else git, else nothing.
 */
const appVersion: string = JSON.parse(readFileSync(path.resolve(__dirname, 'package.json'), 'utf8')).version
function appBuild(): string {
  if (process.env.APP_BUILD) return process.env.APP_BUILD
  try {
    return execSync('git rev-parse --short HEAD', { cwd: __dirname, stdio: ['ignore', 'pipe', 'ignore'] }).toString().trim()
  } catch {
    return ''
  }
}

// https://vitejs.dev/config/
export default defineConfig(({ mode }) => ({
  plugins: [
    vue(),
    legacy(),
    webManifest(loadEnv(mode, process.cwd()).VITE_APP_NAME ?? ''),
  ],
  define: {
    __APP_VERSION__: JSON.stringify(appVersion),
    __APP_BUILD__: JSON.stringify(appBuild()),
  },
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
