/**
 * The golden rule (spec §4, CLAUDE.md règle 1), end to end: a friend
 * reserves and comments in the secret zone; the owner's own screens
 * show none of it.
 */
describe('Surprise', () => {
  it("a friend's reservation stays invisible to the owner", () => {
    cy.createUser('Camille').then((camille) => {
      cy.createUser('Hugo').then((hugo) => {
        cy.befriend(camille, hugo);
        cy.apiAs(camille, 'POST', '/ideas', { title: 'Casque audio', priceAmount: '180' }).then(({ body: idea }) => {
          // Hugo, in the secret zone of Camille's idea.
          cy.loginUi(hugo);
          cy.visit(`/tabs/friends/${camille.id}`);
          cy.tap('Casque audio', 'a');
          cy.location('pathname').should('eq', `/ideas/${idea.id}`);
          cy.see('Entre amis · invisible pour Camille');
          cy.tap("Je l'offre", 'ion-button');
          cy.see("Vous l'offrez");
          cy.fillField('Ajouter un commentaire', 'Je le prends, chut !');
          cy.tap('Envoyer', 'ion-button');
          cy.see('Je le prends, chut !');

          // Camille sees her idea, and nothing of that.
          cy.clearAllLocalStorage();
          cy.loginUi(camille);
          cy.see('Casque audio');
          cy.notSee('Réservé');
          cy.visit(`/ideas/${idea.id}`);
          cy.see('Casque audio');
          cy.notSee('Entre amis');
          cy.notSee('Je le prends');
          cy.notSee('Hugo');
        });
      });
    });
  });
});
