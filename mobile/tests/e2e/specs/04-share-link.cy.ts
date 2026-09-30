/**
 * Spec §5.16: the owner creates a share link; a visitor without an
 * account sees the guest view only, signs in, confirms, and they are
 * friends at once.
 */
describe('Share link', () => {
  it('goes from the guest view to an immediate friendship', () => {
    cy.createUser('Camille').then((camille) => {
      cy.createUser('Lucas').then((lucas) => {
        cy.apiAs(camille, 'POST', '/ideas', { title: 'Plaid en laine', priceAmount: '75', occasion: 'christmas' });
        cy.apiAs(camille, 'POST', '/ideas', { title: 'Brouillon caché', visibility: 'private' });

        // Camille creates her link, with the confirmation, and previews it.
        cy.loginUi(camille);
        cy.visit('/profile/share');
        cy.see("Aucun lien n'existe : le partage est désactivé.");
        cy.tap('Créer le lien', 'ion-button');
        cy.alertButton('Créer le lien');
        cy.see('Actif');
        cy.see('Aucun ami ajouté via ce lien');
        cy.tap('Aperçu de la vue invité', 'a');
        cy.see('Les idées cadeaux de Camille');
        cy.see('Plaid en laine');
        cy.notSee('Brouillon caché');

        cy.apiAs(camille, 'GET', '/share-link').then(({ body }) => {
          const token = body.link.token as string;

          // Lucas, signed out: the guest view, read-only.
          cy.clearAllLocalStorage();
          cy.visit(`/u/${token}`);
          cy.see('Les idées cadeaux de Camille');
          cy.see('Vue invité · lecture seule');
          cy.see('Plaid en laine');
          cy.notSee('Brouillon caché');
          cy.tap("Je l’offre", 'ion-button');
          cy.alertButton('J’ai déjà un compte');

          // Signing in leads to the confirmation, kept through the detour.
          cy.location('pathname').should('eq', '/login');
          cy.fillField('Email', lucas.email);
          cy.fillField('Mot de passe', lucas.password);
          cy.tap('Se connecter', 'ion-button');
          cy.location('pathname').should('eq', `/join/${token}`);
          cy.see('Devenir ami avec Camille ?');
          cy.tap('Devenir amis', 'ion-button');

          cy.location('pathname').should('eq', `/tabs/friends/${camille.id}`);
          cy.see('Plaid en laine');
          cy.apiAs(camille, 'GET', '/share-link').its('body.link.joinCount').should('eq', 1);
        });
      });
    });
  });

  it('shows the same answer for an invalid link', () => {
    cy.visit('/u/AAAAAAAAAAAAAAAAAAAAAA');
    cy.see('Lien invalide ou expiré.');
  });
});
