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
