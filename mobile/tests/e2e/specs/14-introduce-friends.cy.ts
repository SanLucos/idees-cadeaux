/** Spec §5.3, §11 décision 65: Luc introduces Bruno to Anna; Anna asks, Bruno accepts, Luc hears of it. */
/** Toasts render their text in a shadow root. */
const toast = (message: string) => cy.get('ion-toast').should(($toasts) => {
  expect([...$toasts].map((el) => (el as HTMLElement & { message?: string }).message)).to.include(message);
});

describe('Introduce friends', () => {
  it('suggests a friend to another, who sends a request from the Amis tab', () => {
    cy.createUser('Luc').then((luc) => {
      cy.createUser('Anna').then((anna) => {
        cy.createUser('Bruno').then((bruno) => {
          cy.befriend(luc, anna);
          cy.befriend(luc, bruno);

          // From Bruno's page, Luc picks Anna among his friends.
          cy.loginUi(luc);
          cy.visit(`/tabs/friends/${bruno.id}`);
          // Below the ideas: they are what the page is for.
          cy.get('[data-cy=introduce]').should(($button) => {
            const ideas = Cypress.$('ion-content h2').filter((_, h) => /Ses idées|idée/.test(h.textContent ?? '')).first();
            expect(ideas.length, 'ideas title').to.equal(1);
            expect(ideas[0].compareDocumentPosition($button[0]) & Node.DOCUMENT_POSITION_FOLLOWING, 'after the ideas').to.be.greaterThan(0);
          });
          cy.tap('Présenter à des amis…', 'ion-button');
          cy.get('ion-alert').should('be.visible').contains('button', 'Anna').click();
          cy.get('ion-alert').contains('button', 'Suggérer').click();
          toast('Suggestion envoyée.');
          cy.see('Présenté à');
          cy.see('En attente');
          cy.audit('friend list, introduced');

          // Anna sees Bruno's pseudo, and who suggested him.
          cy.clearCookies(); // Luc's web session (spec §11 décision 56)
          cy.loginUi(anna);
          cy.visit('/tabs/friends');
          cy.get('[data-cy=friend-suggestion]').should('contain', 'Bruno').and('contain', 'Luc vous suggère cette personne');
          cy.audit('friends tab, a suggestion');
          cy.tap('Envoyer une demande', 'ion-button');
          toast('Demande envoyée.');
          cy.get('[data-cy=friend-suggestion]').should('not.exist');

          // Bruno gets an ordinary request, « suggéré par Luc », and accepts it.
          cy.clearCookies();
          cy.loginUi(bruno);
          cy.visit('/tabs/friends');
          cy.see('suggéré par Luc');
          // The tap only starts the request: ask Luc's notifications once it is answered.
          cy.intercept('POST', '**/api/friendships/*/accept').as('accept');
          cy.tap('Accepter', 'ion-button');
          cy.wait('@accept').its('response.statusCode').should('eq', 200);

          // Luc is told, and the suggestion is gone from Bruno's page.
          cy.apiAs(luc, 'GET', '/notifications').its('body.member').should((items) => {
            expect(items.map((i: { type: string }) => i.type)).to.include('friend_suggestion_accepted');
          });
          cy.apiAs(luc, 'GET', '/friend-suggestions?sent=1').its('body').should('have.length', 0);
        });
      });
    });
  });
});
