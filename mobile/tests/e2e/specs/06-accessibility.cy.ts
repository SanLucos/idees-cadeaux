/**
 * Spec §9 "Accessibilité": every main screen passes axe-core's WCAG 2.1
 * A/AA rules (labels for VoiceOver/TalkBack, contrast, names of controls),
 * in the views that matter: owner, friend (secret zone), guest.
 */
describe('Accessibility', () => {
  it('signed-out screens', () => {
    cy.visit('/login');
    cy.see('Se connecter');
    cy.audit('login');

    cy.visit('/register');
    cy.see("S'inscrire");
    cy.audit('register');
  });

  it('signed-in screens', () => {
    cy.createUser('Camille').then((camille) => {
      cy.createUser('Hugo').then((hugo) => {
        cy.befriend(camille, hugo);
        cy.apiAs(camille, 'POST', '/ideas', { title: 'Casque audio', priceAmount: '180', occasion: 'birthday' }).then(({ body: idea }) => {
          cy.apiAs(hugo, 'POST', '/reservations', { ideaId: idea.id });
          cy.apiAs(camille, 'POST', '/share-link', { confirmed: true }).then(({ body: share }) => {
            cy.loginUi(camille);
            cy.see('Casque audio');
            cy.audit('my list');

            cy.visit(`/ideas/${idea.id}`);
            cy.see('Casque audio');
            cy.audit('idea, owner view');

            cy.visit('/ideas/new');
            cy.see('Pour qui ?');
            cy.audit('idea form');

            cy.visit('/tabs/friends');
            cy.see('Hugo');
            cy.audit('friends');

            cy.visit('/tabs/activity');
            cy.audit('activity');

            cy.visit('/tabs/profile');
            cy.see('Partager mon profil');
            cy.audit('profile');

            cy.visit('/profile/share');
            cy.see('Actif');
            cy.audit('share my profile');

            cy.visit('/settings/delete-account');
            cy.see('14 jours pour changer');
            cy.audit('delete account');

            // Hugo: the friend view with its secret zone.
            cy.clearAllLocalStorage();
            cy.loginUi(hugo);
            cy.visit(`/tabs/friends/${camille.id}`);
            cy.see('Casque audio');
            cy.audit("friend's list");
            cy.visit(`/ideas/${idea.id}`);
            cy.see('Entre amis');
            cy.audit('idea, friend view');

            // Signed out: the guest view.
            cy.clearAllLocalStorage();
            cy.visit(`/u/${share.link.token}`);
            cy.see('Vue invité · lecture seule');
            cy.audit('guest view');
          });
        });
      });
    });
  });
});
