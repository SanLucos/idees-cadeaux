import { defineConfig } from 'cypress';

/**
 * End-to-end journeys against the local stack (docker compose up -d) and
 * a Vite server: `npm run dev` then `npx cypress run`. Accounts are made
 * fresh on each run through the API; verification codes are read from
 * Mailpit. URLs are overridable (CYPRESS_BASE_URL, CYPRESS_API_URL,
 * CYPRESS_MAILPIT_URL).
 */
export default defineConfig({
  e2e: {
    supportFile: 'tests/e2e/support/e2e.{js,jsx,ts,tsx}',
    specPattern: 'tests/e2e/specs/**/*.cy.{js,jsx,ts,tsx}',
    videosFolder: 'tests/e2e/videos',
    screenshotsFolder: 'tests/e2e/screenshots',
    baseUrl: process.env.CYPRESS_BASE_URL ?? 'http://localhost:5173',
    viewportWidth: 390,
    viewportHeight: 844,
    defaultCommandTimeout: 10000,
    video: false,
    setupNodeEvents(on) {
      on('task', {
        log(message: string) {
          console.log(message);
          return null;
        },
      });
    },
    env: {
      apiUrl: process.env.CYPRESS_API_URL ?? 'http://localhost:8000/api',
      mailpitUrl: process.env.CYPRESS_MAILPIT_URL ?? 'http://localhost:8026',
    },
  },
});
