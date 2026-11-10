import 'cypress-axe';
import './commands';

// Each journey starts signed out: no session, no pending link or share.
// The device's offline copy is wiped by the app itself when another
// account signs in (stores/auth fetchMe), and every journey uses new accounts.
beforeEach(() => {
  cy.clearAllLocalStorage();
  // The web session lives in an httpOnly cookie (spec §11 décision 56).
  cy.clearAllCookies();
  cy.clearAllSessionStorage();
});

// A blank page in CI says nothing by itself: keep the page's own errors
// (uncaught, rejected promises, console.error) and print them in the
// run's output when a journey fails.
const pageErrors: string[] = [];
beforeEach(() => {
  pageErrors.length = 0;
});
Cypress.on('window:before:load', (win) => {
  const keep = (kind: string, message: unknown) => pageErrors.push(`[page ${kind}] ${win.location.pathname}: ${String(message)}`);
  const consoleError = win.console.error.bind(win.console);
  win.console.error = (...args: unknown[]) => {
    keep('console.error', args.map((a) => (a instanceof Error ? (a.stack ?? a.message) : String(a))).join(' '));
    consoleError(...args);
  };
  win.addEventListener('error', (event) => keep('error', event.error?.stack ?? event.message));
  win.addEventListener('unhandledrejection', (event) => keep('unhandledrejection', event.reason?.stack ?? event.reason));
});
afterEach(function () {
  if ('failed' === this.currentTest?.state && pageErrors.length) cy.task('log', pageErrors.join('\n'));
});
