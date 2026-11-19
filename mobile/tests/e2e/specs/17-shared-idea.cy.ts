/**
 * Spec §11 décision 69: Anne adds « Airfryer » for herself and Luc. It
 * shows on both lists; Paul, a friend of both, reserves it once — and
 * neither Anne nor Luc sees any trace of it.
 */
describe('An idea for several people', () => {
  it('is one idea on each list, reserved for both', () => {
    cy.createUser('Anne').then((anne) => {
      cy.createUser('Luc').then((luc) => {
        cy.createUser('Paul').then((paul) => {
          cy.befriend(anne, luc);
          cy.befriend(anne, paul);
          cy.befriend(luc, paul);

          // Anne picks herself and Luc.
          cy.loginUi(anne);
          cy.visit('/ideas/new');
          cy.get('.recipient').should('have.length.at.least', 3);
          cy.tap('Plusieurs personnes', 'ion-toggle');
          cy.contains('.recipient', 'Luc').click();
          cy.get('.recipient--selected').should('have.length', 2);
          cy.see('Une seule idée pour tous');
          cy.fillField('Titre', 'Airfryer');
          cy.audit('idea form, several people');
          cy.tap("Publier l'idée", 'ion-button');
          cy.location('pathname').should('eq', '/tabs/list');
          cy.see('Pour Anne et Luc');

          // Paul sees it on Luc's list and reserves it.
          cy.clearCookies();
          cy.loginUi(paul);
          cy.visit(`/tabs/friends/${luc.id}`);
          cy.contains('Airfryer').click();
          cy.see('Pour Anne et Luc');
          cy.tap("Je l'offre", 'ion-button');
          cy.see("Vous l'offrez");
          // The same idea on Anne's list: reserved there too.
          cy.apiAs(paul, 'GET', `/users/${anne.id}/ideas`).its('body.member').should((ideas: { title: string; reservation: { isMine: boolean } | null }[]) => {
            expect(ideas.map((i) => [i.title, i.reservation?.isMine])).to.deep.equal([['Airfryer', true]]);
          });

          // Luc has it on his own list, with nothing of Paul's reservation.
          cy.clearCookies();
          cy.loginUi(luc);
          cy.visit('/tabs/list');
          cy.see('Airfryer');
          cy.see('Pour Anne et Luc');
          cy.contains('Airfryer').click();
          cy.notSee("Je l'offre");
          cy.notSee('Paul');
          cy.audit('shared idea, a recipient');
        });
      });
    });
  });
});
