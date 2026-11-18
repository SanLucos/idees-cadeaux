/** Spec §11 décision 64 (révisée) : the active profile is switched from the Profil tab only. */
describe('Profile switch', () => {
  it('switches from the Profil tab only, tints the background and says whose list it is', () => {
    cy.createUser('Luc').then((luc) => {
      cy.loginUi(luc);
      cy.visit('/profile/children/new');
      cy.fillField('Pseudo', 'Jules');
      cy.tap("Je suis titulaire de l'autorité parentale", 'ion-checkbox');
      cy.tap('Créer le profil', 'ion-button');
      cy.location('pathname').should('match', /^\/profile\/children\/[0-9a-f-]{36}$/);

      // No switcher on the other tabs.
      cy.visit('/tabs/friends');
      cy.see('Mes amis');
      cy.get('.switcher:visible, .profile-greeting:visible').should('not.exist');
      cy.visit('/tabs/list');
      cy.see('Bonjour Luc');
      cy.get('.switcher:visible, .profile-greeting:visible').should('not.exist');

      // From the Profil tab: the menu opens right under the switcher.
      cy.visit('/tabs/profile');
      cy.get('.switcher:visible').should('contain.text', 'Luc').click();
      cy.get('ion-popover.ic-profile-menu').should('be.visible').contains('ion-item', 'Luc');
      cy.audit('profile menu');
      cy.get('ion-popover.ic-profile-menu').contains('ion-item', 'Jules').click();
      cy.get('ion-popover.ic-profile-menu').should('not.exist');
      cy.get('.switcher:visible').should('contain.text', 'Jules');
      cy.get('html').should('have.attr', 'data-profile-tint', '0');

      // Still Jules on the other tabs, said in plain text.
      cy.visit('/tabs/list');
      cy.see('Liste de Jules');
      cy.audit('my list, acting as a child');
      cy.visit('/tabs/friends');
      cy.see('Amis de Jules');

      // Back to me, from the Profil tab.
      cy.visit('/tabs/profile');
      cy.tap('Revenir à mon profil', 'ion-button');
      cy.get('.switcher:visible').should('contain.text', 'Luc');
      cy.get('html').should('not.have.attr', 'data-profile-tint');
    });
  });
});
