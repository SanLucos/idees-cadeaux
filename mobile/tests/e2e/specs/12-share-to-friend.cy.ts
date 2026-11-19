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

        // Issue #7: back on Hugo's list, where the suggestion now is.
        cy.location('pathname').should('eq', `/tabs/friends/${hugo.id}`);
        cy.see('Platine vinyle');
        cy.apiAs(luc, 'GET', `/users/${hugo.id}/ideas?kind=suggestion`).its('body.member').should((ideas: { id: string; title: string; recipients: { id: string }[]; isSuggestion: boolean }[]) => {
          expect(ideas).to.have.length(1);
          expect(ideas[0].title).to.equal('Platine vinyle');
          expect(ideas[0].recipients.map((r) => r.id)).to.deep.equal([hugo.id]);
          expect(ideas[0].isSuggestion).to.equal(true);
        }).then((ideas) => {
          // The golden rule: Hugo never sees it.
          cy.apiAs(hugo, 'GET', `/ideas/${ideas[0].id}`).its('status').should('equal', 404);
        });
      });
    });
  });
});
