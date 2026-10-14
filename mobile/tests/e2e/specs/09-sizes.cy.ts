/** Spec §5.2, §11 décisions 57 and 58: size units, and a child's sizes on its page. */
const chooseUnit = (unit: string) => {
  cy.get('ion-select').filter((_, el) => el.getClientRects().length > 0).first().click();
  cy.get('ion-popover').should('be.visible').contains('ion-radio, ion-item', new RegExp(`^\\s*${unit}\\s*$`)).click();
};

describe('Sizes', () => {
  it('keeps a size written without a unit, then gets one', () => {
    cy.createUser('Paul').then((paul) => {
      cy.apiAs(paul, 'POST', '/profile_sizes', { label: 'Pointure', value: '42' });
      cy.loginUi(paul);
      cy.visit('/tabs/profile');
      cy.see('42');

      cy.tap('Actions pour Pointure', 'ion-button');
      cy.get('ion-action-sheet').should('be.visible').contains('button', 'Modifier').click();
      chooseUnit('EU');
      cy.tap('Enregistrer', 'ion-button');

      cy.see('42 EU');
      cy.apiAs(paul, 'GET', '/profile_sizes').its('body.member.0').should('include', { value: '42', unit: 'eu' });
    });
  });

  it("edits a child's sizes from the child's page", () => {
    cy.createUser('Lea').then((lea) => {
      cy.apiAs(lea, 'POST', '/managed-profiles', { displayName: 'Hugo', parentalConsent: true }).then(({ body: hugo }) => {
        cy.loginUi(lea);
        cy.visit(`/profile/children/${hugo.id}`);
        cy.tap('Ajouter une taille', 'ion-item');
        cy.fillField('Libellé', 'Taille');
        cy.fillField('Valeur', '110');
        chooseUnit('cm');
        cy.tap('Ajouter', 'ion-button');

        cy.see('110 cm');
        cy.apiAs(lea, 'GET', `/profile_sizes?userId=${hugo.id}`).its('body.member.0').should('include', { label: 'Taille', unit: 'cm' });
        cy.apiAs(lea, 'GET', '/profile_sizes').its('body.member').should('have.length', 0);
      });
    });
  });
});
