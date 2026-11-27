/**
 * The computer layout (spec §11 décision 72): from 1024 px, a side
 * navigation instead of the tab bar, every idea of a list in one table
 * with its filters (kept between visits), and an idea's public half next
 * to its secret zone. The owner's table still shows nothing hidden.
 */
describe('Computer layout', () => {
  beforeEach(() => cy.viewport(1280, 800));

  it('lists my ideas and a friend’s ideas in tables, with filters', () => {
    cy.createUser('Luc').then((luc) => {
      cy.createUser('Camille').then((camille) => {
        cy.befriend(luc, camille);
        // Luc's own list: published, draft, archived.
        cy.apiAs(luc, 'POST', '/ideas', { title: 'Platine vinyle', priceAmount: '149', occasion: 'birthday' });
        cy.apiAs(luc, 'POST', '/ideas', { title: 'Carnet de croquis', visibility: 'private' });
        cy.apiAs(luc, 'POST', '/ideas', { title: 'Bonnet en alpaga', occasion: 'christmas' }).then(({ body }) =>
          cy.apiAs(luc, 'POST', `/ideas/${body.id}/archive`, { kind: 'received' }),
        );
        // Camille's list: her ideas (one reserved by Luc, one received), Luc's suggestion and draft.
        cy.apiAs(camille, 'POST', '/ideas', { title: 'Casque audio', priceAmount: '180', occasion: 'birthday' }).as('casque');
        cy.apiAs(camille, 'POST', '/ideas', { title: 'Plaid en laine', priceAmount: '75', occasion: 'christmas' }).then(({ body }) =>
          cy.apiAs(luc, 'POST', '/reservations', { ideaId: body.id }),
        );
        cy.apiAs(camille, 'POST', '/ideas', { title: 'Montre de plongée' }).then(({ body }) =>
          cy.apiAs(camille, 'POST', `/ideas/${body.id}/archive`, { kind: 'received' }),
        );
        cy.apiAs(luc, 'POST', '/ideas', { title: 'Atelier dégustation de thés', ownerId: camille.id });
        cy.apiAs(luc, 'POST', '/ideas', { title: 'Carnet de voyage', ownerId: camille.id, visibility: 'private' });

        cy.loginUi(luc);

        // Side navigation, no tab bar.
        cy.get('ion-menu nav').within(() => {
          for (const entry of ['Ma liste', 'Amis', 'Mes cadeaux', 'Activité', 'Profil']) cy.contains('ion-item', entry);
        });
        cy.get('ion-tab-bar').should('not.be.visible');

        // Ma liste: archives hidden by default.
        cy.get('.idea-table tbody tr').should('have.length', 2);
        cy.see('Platine vinyle');
        cy.see('Carnet de croquis');
        cy.see('Brouillon');
        cy.notSee('Bonnet en alpaga');
        cy.see('1 archivée masquée');
        cy.screenshot('desktop/my-list', { capture: 'viewport', overwrite: true });
        cy.audit('Ma liste (ordinateur)');

        // The « Archivées » filter adds them, and is kept.
        cy.tap('Archivées · 1', 'ion-chip');
        cy.see('Bonnet en alpaga');
        cy.reload();
        cy.see('Bonnet en alpaga');
        cy.get('.idea-table tbody tr').should('have.length', 3);

        // Occasion filter and sort by price.
        cy.tap('Noël · 1', 'ion-chip');
        cy.get('.idea-table tbody tr').should('have.length', 1);
        cy.tap('Toutes', 'ion-chip');
        cy.tap('Prix', 'button');
        cy.get('.idea-table tbody tr').first().should('contain', 'Platine vinyle');

        // A friend's list: one table, sources and statuses as columns.
        cy.tap('Amis', 'ion-item');
        cy.tap('Camille', 'ion-item');
        cy.location('pathname').should('eq', `/tabs/friends/${camille.id}`);
        cy.get('.idea-table tbody tr').should('have.length', 4);
        cy.see('Idée de Camille');
        cy.see('Suggérée par vous');
        cy.see('Mon brouillon');
        cy.see('Réservé par vous');
        cy.see('Libre');
        cy.notSee('Montre de plongée');
        // Let the page transition end before the picture.
        cy.wait(600);
        cy.screenshot('desktop/friend-list', { capture: 'viewport', overwrite: true });
        cy.audit('Liste d’un ami (ordinateur)');

        cy.tap('Archivées · 1', 'ion-chip');
        cy.see('Montre de plongée');
        cy.see('Reçue');
        cy.tap('Mes brouillons · 1', 'ion-chip');
        cy.notSee('Carnet de voyage');

        // The idea: public half and secret zone side by side.
        cy.tap('Casque audio', 'a');
        cy.get('@casque').then(({ body }: any) => cy.location('pathname').should('eq', `/ideas/${body.id}`));
        cy.see('Entre amis');
        cy.get('.idea--split').should(($split) => {
          const [publicHalf, secret] = [...$split.children()].map((el) => el.getBoundingClientRect());
          expect(secret.left, 'secret zone on the right').to.be.greaterThan(publicHalf.right);
        });
        cy.screenshot('desktop/idea', { capture: 'viewport', overwrite: true });

        // Camille's own table: nothing of what her friends do.
        cy.clearAllLocalStorage();
        cy.clearAllCookies();
        cy.loginUi(camille);
        cy.tap('Archivées · 1', 'ion-chip');
        cy.get('.idea-table tbody tr').should('have.length', 3);
        cy.notSee('Atelier dégustation de thés');
        cy.notSee('Carnet de voyage');
        cy.notSee('Réservé');
        cy.notSee('Libre');
        cy.get('.idea-table th').should('not.contain', 'Statut').and('not.contain', 'Entre amis');
      });
    });
  });

  it('keeps the phone layout below 1024 px', () => {
    cy.viewport(900, 800);
    cy.createUser('Wanda').then((wanda) => {
      cy.loginUi(wanda);
      cy.get('ion-tab-bar').should('be.visible');
      cy.get('ion-menu').should('not.exist');
      cy.get('ion-app').should(($app) => expect($app[0].getBoundingClientRect().width).to.equal(480));
    });
  });
});
