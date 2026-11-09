/** Spec §11 décision 64: whose app this is, on every tab, and switching from there. */
describe('Profile switch', () => {
  it('greets the active profile on every tab, tints the background and switches from the greeting', () => {
    cy.createUser('Luc').then((luc) => {
      cy.loginUi(luc);
      cy.visit('/profile/children/new');
      cy.fillField('Pseudo', 'Jules');
      cy.tap("Je suis titulaire de l'autorité parentale", 'ion-checkbox');
      cy.tap('Créer le profil', 'ion-button');
      cy.location('pathname').should('match', /^\/profile\/children\/[0-9a-f-]{36}$/);

      // From the Amis tab: « Bonjour Luc » opens the profile choice.
      cy.visit('/tabs/friends');
      cy.get('.profile-greeting--switch').should('contain.text', 'Bonjour Luc');
      cy.get('html').should('not.have.attr', 'data-profile-tint');
      cy.get('.profile-greeting--switch:visible').click();
      cy.get('ion-action-sheet').should('be.visible').contains('button', 'Jules').click();

      cy.get('.profile-greeting--switch:visible').should('contain.text', 'Bonjour Jules');
      cy.get('html').should('have.attr', 'data-profile-tint', '0');

      // Still Jules on the other tabs.
      cy.visit('/tabs/activity');
      cy.get('.profile-greeting--switch:visible').should('contain.text', 'Bonjour Jules');
      cy.audit('activity, acting as a child');
      cy.visit('/tabs/list');
      cy.get('.profile-greeting--switch:visible').should('contain.text', 'Bonjour Jules');

      // Back to me from Ma liste.
      cy.get('.profile-greeting--switch:visible').click();
      cy.get('ion-action-sheet').should('be.visible').contains('button', 'Luc').click();
      cy.get('.profile-greeting--switch:visible').should('contain.text', 'Bonjour Luc');
      cy.get('html').should('not.have.attr', 'data-profile-tint');
    });
  });
});
