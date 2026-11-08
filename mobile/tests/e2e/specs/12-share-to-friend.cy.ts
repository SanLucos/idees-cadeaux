/**
 * Spec §5.6: a link shared to the app opens the new idea form, for me by
 * default, a friend remaining possible (a suggestion). The web app opens
 * /share?url=… on a fresh page load, outside the tabs: its friends must
 * still be offered (spec §11 décision 56, nothing kept in the browser).
 */
describe('Share to the app', () => {
  it('suggests a shared link to a friend', () => {
    cy.createUser('Luc').then((luc) => {
      cy.createUser('Hugo').then((hugo) => {
        cy.befriend(luc, hugo);
        cy.loginUi(luc);

        cy.visit('/share?url=https%3A%2F%2Fexample.invalid%2Fvinyle&title=Platine%20vinyle');
        cy.location('pathname').should('eq', '/ideas/new');
        cy.contains('.recipient', 'Hugo').click();
        cy.see("C'est une suggestion : Hugo n'en saura rien.");
        cy.get('input[type="url"]').should('have.value', 'https://example.invalid/vinyle');
        cy.tap('Publier la suggestion', 'ion-button');

        cy.location('pathname').should('match', /^\/ideas\/[0-9a-f-]{36}$/).then((path) => {
          cy.apiAs(luc, 'GET', path).its('body').should((idea) => {
            expect(idea.title).to.equal('Platine vinyle');
            expect(idea.ownerId).to.equal(hugo.id);
            expect(idea.isSuggestion).to.equal(true);
          });
          // The golden rule: Hugo never sees it.
          cy.apiAs(hugo, 'GET', path).its('status').should('equal', 404);
        });
      });
    });
  });
});
