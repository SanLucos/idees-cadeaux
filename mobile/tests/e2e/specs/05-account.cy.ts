/** Spec §5.13: export by email, account deletion with 14 days of grace, cancellation. */
describe('Account data', () => {
  it('exports my data through a link sent by email', () => {
    cy.createUser('Nina').then((nina) => {
      cy.loginUi(nina);
      cy.visit('/tabs/profile');
      cy.tap('Exporter mes données', 'ion-item');
      cy.location('pathname').should('eq', '/settings/export');
      cy.fillField('Votre mot de passe', nina.password);
      cy.tap("Demander l'export", 'ion-button');
      cy.see("C'est parti");

      cy.mailLink(nina.email, '/exports/').then((link) => {
        cy.request({ url: link.replace(/^https?:\/\/[^/]+/, 'http://localhost:8000'), encoding: 'binary' }).then((response) => {
          expect(response.status).to.eq(200);
          expect(response.headers['content-type']).to.eq('application/zip');
        });
      });
    });
  });

  it('schedules the deletion, shows only the grace-period screen, then cancels', () => {
    cy.createUser('Omar').then((omar) => {
      cy.loginUi(omar);
      cy.visit('/settings/delete-account');
      cy.see('14 jours pour changer d');
      cy.fillField('Votre mot de passe', omar.password);
      cy.tap('Je comprends', 'ion-checkbox');
      cy.get('ion-button[color="danger"]').filter((_, el) => el.getClientRects().length > 0).click();
      cy.alertButton('Supprimer mon compte');

      cy.location('pathname').should('eq', '/account/deletion-scheduled');
      cy.see('Votre compte sera supprimé le');
      cy.mailLink(omar.email, '/account/cancel-deletion/').should('contain', '/account/cancel-deletion/');

      // Nothing else is reachable meanwhile.
      cy.visit('/tabs/friends');
      cy.location('pathname').should('eq', '/account/deletion-scheduled');

      cy.tap('Annuler la suppression', 'ion-button');
      cy.location('pathname').should('eq', '/tabs/list');
      cy.apiAs(omar, 'GET', '/users/me').its('body.deletionScheduledAt').should('be.null');
    });
  });
});
