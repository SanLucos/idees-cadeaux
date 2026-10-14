/** Spec §5.15, §11 décisions 59 and 60: a child shared with my friends, managed by two parents. */
describe('Family', () => {
  it('shares a new child with my friends, then a second parent manages it too', () => {
    cy.createUser('Luc').then((luc) => {
      cy.createUser('Claire').then((claire) => {
        cy.createUser('Marc').then((marc) => {
          cy.befriend(luc, claire);
          cy.befriend(luc, marc);

          // Ticked by default: Luc's friends see Jules with no request.
          cy.loginUi(luc);
          cy.visit('/profile/children/new');
          cy.fillField('Pseudo', 'Jules');
          cy.tap("Je suis titulaire de l'autorité parentale", 'ion-checkbox');
          cy.tap('Créer le profil', 'ion-button');
          cy.location('pathname').should('match', /^\/profile\/children\/[0-9a-f-]{36}$/);
          cy.apiAs(marc, 'GET', '/friendships').its('body').should((friends) => {
            expect(friends.map((f: { user: { displayName: string } }) => f.user.displayName)).to.include('Jules');
          });

          // Claire is invited among Luc's friends…
          cy.tap('Ajouter un gestionnaire', 'ion-button');
          cy.get('ion-alert').should('be.visible').contains('button', 'Claire').click();
          cy.get('ion-alert').contains('button', 'Ajouter un gestionnaire').click();
          cy.see('Invitation envoyée à Claire');

          // …and accepts from her profile tab, with the parental consent.
          cy.clearCookies(); // Luc's web session (spec §11 décision 56)
          cy.loginUi(claire);
          cy.visit('/tabs/profile');
          cy.see('vous invite à gérer aussi le profil de Jules');
          cy.tap('Accepter', 'ion-button');
          cy.get('ion-alert').should('be.visible').contains('button', "Je suis titulaire de l'autorité parentale").click();
          cy.get('ion-alert').contains('button', 'Accepter').click();
          cy.location('pathname').should('match', /^\/profile\/children\/[0-9a-f-]{36}$/);
          cy.see('Claire (vous)');
          cy.see('Luc');
          cy.audit('child page, two managers');
        });
      });
    });
  });
});
