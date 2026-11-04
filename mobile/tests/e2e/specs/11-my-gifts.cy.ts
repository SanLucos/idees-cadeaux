/**
 * « Mes cadeaux pour mes amis » (spec §11 décision 62): what I reserve
 * shows there, then moves to « Offerts » once my friend marks it
 * received — and the friend's own screens still show nothing of it.
 */
describe('My gifts', () => {
  it('lists my reservation, then my friend having received it', () => {
    cy.createUser('Camille').then((camille) => {
      cy.createUser('Hugo').then((hugo) => {
        cy.befriend(camille, hugo);
        cy.apiAs(camille, 'POST', '/ideas', { title: 'Casque audio', priceAmount: '180' }).then(({ body: idea }) => {
          cy.apiAs(camille, 'POST', '/ideas', { title: 'Livre de cuisine' });
          cy.apiAs(hugo, 'POST', '/reservations', { ideaId: idea.id });

          cy.loginUi(hugo);
          cy.visit('/tabs/friends');
          cy.tap('Mes cadeaux pour mes amis', 'a');
          cy.location('pathname').should('eq', '/gifts');
          cy.see('Camille');
          cy.see('Casque audio');
          cy.see("Vous l'offrez");
          cy.notSee('Livre de cuisine');

          // Camille marks it received: it moves to « Offerts ».
          cy.apiAs(camille, 'POST', `/ideas/${idea.id}/archive`, { kind: 'received' });
          cy.reload();
          cy.tap('Offerts · 1', 'ion-chip');
          cy.see('Casque audio');
          cy.see('Reçu');
          cy.tap('Réservés · 0', 'ion-chip');
          cy.see('Aucun cadeau ne correspond à ces filtres.');
          cy.audit('Mes cadeaux pour mes amis');

          // Camille's own screens: no entry about her list, nothing of Hugo.
          cy.clearAllLocalStorage();
          cy.clearAllCookies();
          cy.loginUi(camille);
          cy.tap('Mes cadeaux pour mes amis', 'a');
          cy.see("Vous n'avez encore rien réservé.");
          cy.notSee('Casque audio');
        });
      });
    });
  });
});
