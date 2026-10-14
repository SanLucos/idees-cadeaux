/**
 * The web app (spec §11 décision 56): the session survives a reload through
 * its httpOnly cookie, no token is ever readable by the page, signing out
 * ends it, and on a computer the phone layout sits in a centred column.
 */
describe('Web app', () => {
  it('keeps the session in an httpOnly cookie only', () => {
    cy.createUser('Wanda').then((wanda) => {
      cy.loginUi(wanda);
      cy.see('Ma liste');

      cy.getCookie('refresh_token').should((cookie) => {
        expect(cookie, 'session cookie').to.not.equal(null);
        expect(cookie!.httpOnly).to.equal(true);
        expect(cookie!.sameSite).to.equal('strict');
        expect(cookie!.path).to.equal('/api/auth');
      });
      cy.window().then((win) => {
        const stored = JSON.stringify({ ...win.localStorage }) + JSON.stringify({ ...win.sessionStorage });
        expect(stored, 'browser storage').not.to.match(/eyJ|refresh_token|auth\./);
        expect(win.document.cookie, 'cookies readable by the page').not.to.contain('refresh_token');
      });

      cy.reload();
      cy.location('pathname').should('eq', '/tabs/list');
      cy.see('Ma liste');

      cy.visit('/tabs/profile');
      cy.tap('Se déconnecter', 'ion-item');
      cy.location('pathname').should('eq', '/login');
      cy.getCookie('refresh_token').should('equal', null);
      cy.visit('/tabs/list');
      cy.location('pathname').should('eq', '/login');
    });
  });

  it('centres the phone layout on a computer screen', () => {
    cy.viewport(1280, 800);
    cy.visit('/login');
    cy.see('Se connecter');
    cy.get('ion-app').should(($app) => {
      const box = $app[0].getBoundingClientRect();
      expect(box.width).to.equal(480);
      expect(Math.round(box.left)).to.equal(400);
    });
    cy.screenshot('web/desktop-login', { capture: 'viewport', overwrite: true });
  });
});
