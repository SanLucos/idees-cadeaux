/** Spec §5.4: publish an idea, keep a private draft, find both in « Ma liste ». */
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

      cy.location('pathname').should('match', /^\/ideas\/[0-9a-f-]{36}$/);
      cy.see('Platine vinyle');
      cy.see('149');

      cy.visit('/ideas/new');
      cy.fillField('Titre', 'Idée secrète');
      cy.tap('Brouillon privé', 'ion-radio');
      cy.tap('Enregistrer le brouillon', 'ion-button');
      cy.location('pathname').should('match', /^\/ideas\/[0-9a-f-]{36}$/);

      cy.visit('/tabs/list');
      cy.see('Platine vinyle');
      cy.notSee('Idée secrète');
      cy.tap('Brouillons', 'ion-segment-button');
      cy.see('Idée secrète');
    });
  });
});
