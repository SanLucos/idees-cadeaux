/**
 * Spec §5.17, §11 décision 71: the superadmin introduces Anna and Bruno
 * (both accept), makes Anna and Chloé friends silently, then removes
 * that friendship.
 */
/** Toasts render their text in a shadow root. */
const toast = (message: string) => cy.get('ion-toast').should(($toasts) => {
  expect([...$toasts].map((el) => (el as HTMLElement & { message?: string }).message)).to.include(message);
});

const search = (selector: string, text: string) => cy.get(`${selector} input`).filter((_, el) => el.getClientRects().length > 0).clear().type(text);

describe('Back-office', () => {
  it('introduces two people, makes two others friends, then removes it', () => {
    cy.createUser('Admin').then((admin) => {
      cy.createUser('Anna').then((anna) => {
        cy.createUser('Bruno').then((bruno) => {
          cy.createUser('Chloe').then((chloe) => {
            cy.grantSuperAdmin(admin);

            // Nobody else has the entry, nor the endpoints.
            cy.apiAs(anna, 'GET', '/admin/users').its('status').should('eq', 404);

            cy.loginUi(admin);
            cy.visit('/tabs/profile');
            cy.get('[data-cy=admin-entry]').filter((_, el) => el.getClientRects().length > 0).click();
            cy.location('pathname').should('eq', '/admin');

            // Anna, found by email, signs in with a password.
            search('[data-cy=admin-search]', anna.email);
            cy.get('[data-cy=admin-user]').should('have.length', 1).and('contain', 'Anna');
            cy.get('[data-cy=admin-signin]').should('contain', 'Mot de passe');
            cy.audit('back-office, accounts');
            cy.get('[data-cy=admin-select]').click();
            search('[data-cy=admin-search]', bruno.email);
            cy.get('[data-cy=admin-user]').should('have.length', 1).and('contain', 'Bruno');
            cy.get('[data-cy=admin-select]').click();
            cy.get('[data-cy=admin-selection]').should('contain', '2 sélectionnés');
            cy.get('[data-cy=admin-introduce]').click();
            cy.alertButton('Confirmer');
            toast('1 paire traitée, 0 non disponible');

            // Anna accepts first: nothing yet. Bruno accepts: friends.
            cy.clearCookies();
            cy.loginUi(anna);
            cy.visit('/tabs/friends');
            cy.get('[data-cy=introduction]').should('contain', 'Bruno').and('contain', "L'équipe Idées Cadeaux vous propose de devenir amis");
            cy.audit('friends tab, a team introduction');
            cy.tap('Accepter', 'ion-button');
            toast("C'est noté : vous serez amis si Bruno accepte aussi.");

            cy.clearCookies();
            cy.loginUi(bruno);
            cy.visit('/tabs/friends');
            cy.get('[data-cy=introduction]').should('contain', 'Anna');
            cy.tap('Accepter', 'ion-button');
            toast('Vous êtes maintenant ami avec Anna.');
            cy.apiAs(bruno, 'GET', '/friendships').its('body').should('have.length', 1);

            // From Anna's card: Chloé made a friend, silently, then removed.
            cy.clearCookies();
            cy.loginUi(admin);
            cy.visit('/admin');
            search('[data-cy=admin-search]', anna.email);
            cy.get('[data-cy=admin-user]').should('have.length', 1).click();
            cy.get('[data-cy=admin-card]').should('contain', 'Bruno').and('contain', 'mise en relation');
            search('[data-cy=admin-card-search]', chloe.email);
            cy.get('[data-cy=admin-candidate]').should('have.length', 1);
            cy.get('[data-cy=admin-candidate-force]').click();
            toast('1 paire traitée, 0 non disponible');
            cy.get('[data-cy=admin-friend]').should('have.length', 2).and('contain', 'forcée');
            cy.apiAs(chloe, 'GET', '/notifications').its('body.member').should('have.length', 0);
            cy.apiAs(chloe, 'GET', '/friendships').its('body.0.origin').should('eq', 'request');

            cy.contains('[data-cy=admin-friend]', 'Chloe').find('[data-cy=admin-remove-friend]').click();
            cy.alertButton('Retirer');
            toast('Amitié retirée.');
            cy.get('[data-cy=admin-friend]').should('have.length', 1);
            cy.apiAs(chloe, 'GET', '/friendships').its('body').should('have.length', 0);
          });
        });
      });
    });
  });
});
