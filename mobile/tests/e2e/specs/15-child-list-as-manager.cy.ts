/**
 * GitHub #6, spec §11 décision 66: « Gérer la liste de Jules » opens the
 * child's list as myself — no profile switch — and my own suggestion for
 * the child stays mine, editable, in my own name. The child also shows
 * among my friends (décision 67).
 */
describe("A child's list, read by its manager", () => {
  it('opens without switching profile and lets me edit my suggestion', () => {
    cy.createUser('Luc').then((luc) => {
      cy.loginUi(luc);
      cy.visit('/profile/children/new');
      cy.fillField('Pseudo', 'Jules');
      cy.tap("Je suis titulaire de l'autorité parentale", 'ion-checkbox');
      cy.tap('Créer le profil', 'ion-button');
      cy.location('pathname').should('match', /^\/profile\/children\/[0-9a-f-]{36}$/);

      cy.location('pathname').then((path) => {
        const julesId = path.split('/').pop()!;
        cy.apiAs(luc, 'POST', '/ideas', { title: 'Trottinette', ownerId: julesId });
        cy.reload();

        cy.tap('Gérer la liste de Jules', 'ion-button');
        cy.location('pathname').should('eq', `/tabs/friends/${julesId}`);
        // Still Luc: nothing switched to the child.
        cy.get('html').should('not.have.attr', 'data-profile-tint');
        cy.see('Réservations, suggestions, cotisations et commentaires restent invisibles pour Jules.');
        cy.contains('ion-card, .ic-card, article, a', 'Trottinette').should('contain.text', 'Suggérée par vous');
        cy.audit("child's list as its manager");

        cy.contains('Trottinette').click();
        cy.tap('Modifier', 'ion-button');
        cy.fillField('Titre', 'Trottinette rouge');
        cy.tap('Enregistrer', 'ion-button');
        cy.see('Trottinette rouge');

        // Saved in my own name: still my suggestion, never the child's idea.
        cy.apiAs(luc, 'GET', `/users/${julesId}/ideas?kind=suggestion`).its('body.member').should((ideas: { title: string; isMine: boolean }[]) => {
          expect(ideas.map((i) => [i.title, i.isMine])).to.deep.equal([['Trottinette rouge', true]]);
        });
        cy.apiAs(luc, 'GET', `/users/${julesId}/ideas?kind=personal`).its('body.member').should('have.length', 0);

        // Spec §11 décision 67: my child among my friends, opening the same list.
        cy.visit('/tabs/friends');
        cy.get('[data-cy="my-child"]').should('contain.text', 'Jules').and('contain.text', 'votre enfant').click();
        cy.location('pathname').should('eq', `/tabs/friends/${julesId}`);
        cy.see('Trottinette rouge');
      });
    });
  });
});
