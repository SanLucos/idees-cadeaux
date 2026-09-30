import './commands';

// Each journey starts signed out: no tokens, no pending link or share.
// The device's offline copy is wiped by the app itself when another
// account signs in (stores/auth fetchMe), and every journey uses new accounts.
beforeEach(() => {
  cy.clearAllLocalStorage();
  cy.clearAllSessionStorage();
});
