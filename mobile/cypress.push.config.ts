import { defineConfig } from 'cypress';
import base from './cypress.config';

/**
 * Web Push end to end (spec §11 décision 70), as on a phone where the app
 * was installed from Chrome: the real Google Chrome, the app's real
 * service worker, Google's push service and the worker's Web Push send.
 * Needs Chrome, Internet, the local stack with VAPID keys
 * (backend/.env.local) and a production build served (vite preview):
 *   npm run build && npx vite preview --port 5173
 *   npm run test:e2e:push
 * Not in CI: GitHub's runners would have to reach Google's push service.
 */
export default defineConfig({
  ...base,
  e2e: {
    ...base.e2e,
    specPattern: 'tests/e2e/push/**/*.cy.{js,jsx,ts,tsx}',
    env: { ...base.e2e?.env, realServiceWorker: true },
    defaultCommandTimeout: 20000,
    setupNodeEvents(on, config) {
      base.e2e?.setupNodeEvents?.(on, config);
      // Cypress starts Chrome without background networking, which also
      // cuts Chrome's connection to the push service: no push would come.
      on('before:browser:launch', (browser, launchOptions) => {
        if ('chrome' === browser.family) {
          launchOptions.args = launchOptions.args.filter((arg) => '--disable-background-networking' !== arg);
        }

        return launchOptions;
      });

      return config;
    },
  },
});
