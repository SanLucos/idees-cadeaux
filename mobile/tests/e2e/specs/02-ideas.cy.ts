/**
 * Spec §5.4: publish an idea, keep a private draft, find both in « Ma liste ».
 * Issue #7: saving a new idea lands on the list holding it, not its detail.
 */
describe('Ideas', () => {
  it('publishes an idea and saves a private draft', () => {
    cy.createUser('Camille').then((camille) => {
      cy.loginUi(camille);

      cy.tap('Ajouter une idée', 'ion-button');
      cy.location('pathname').should('eq', '/ideas/new');
      cy.fillField('Titre', 'Platine vinyle');
      cy.fillField('Prix', '149');
      cy.tap('Anniversaire', 'ion-chip');
      cy.tap("Publier l'idée", 'ion-button');

      cy.location('pathname').should('eq', '/tabs/list');
      cy.see('Platine vinyle');
      cy.see('149');

      cy.visit('/ideas/new');
      cy.fillField('Titre', 'Idée secrète');
      cy.tap('Brouillon privé', 'ion-radio');
      cy.tap('Enregistrer le brouillon', 'ion-button');
      // On the drafts tab, the tab bar not bringing it back later.
      cy.location('pathname').should('eq', '/tabs/list');
      cy.location('search').should('eq', '');
      cy.see('Idée secrète');
      cy.notSee('Platine vinyle');

      cy.visit('/tabs/list');
      cy.see('Platine vinyle');
      cy.notSee('Idée secrète');
    });
  });
});
