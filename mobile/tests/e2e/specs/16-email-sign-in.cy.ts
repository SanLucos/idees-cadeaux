import { uniqueEmail } from '../support/commands';

/** Spec §11 décision 68 (issue #8): no password, a link or a code by email. */
describe('Sign in by email', () => {
  it('creates an account from the emailed link', () => {
    const email = uniqueEmail('Lina');

    cy.visit('/register');
    cy.fillField('Email', email);
    cy.tap('Créer un compte sans mot de passe', 'ion-button');
    cy.location('pathname').should('eq', '/login/email');
    cy.tap('Recevoir le lien', 'ion-button');
    cy.see('Un email a été envoyé');

    // The link points at WEB_APP_URL: open its path on the app under test.
    cy.mailLink(email, '/login/email').then((link) => {
      const url = new URL(link);
      cy.visit(url.pathname + url.search);
    });

    cy.location('pathname').should('eq', '/onboarding');
    cy.fillField('Pseudo', 'Lina');
    cy.tap('Continuer', 'ion-button');
    cy.location('pathname').should('eq', '/onboarding/notifications');
  });

  it('signs an existing account in with the code', () => {
    cy.createUser('Malo').then((malo) => {
      cy.visit('/login');
      cy.fillField('Email', malo.email);
      cy.tap('Recevoir un lien de connexion par email', 'ion-button');
      cy.location('pathname').should('eq', '/login/email');
      cy.tap('Recevoir le lien', 'ion-button');
      cy.see('Un email a été envoyé');

      // createUser's verification email came first: wait for the sign-in one.
      cy.mailLink(malo.email, '/login/email').then((link) => {
        const code = new URL(link).searchParams.get('code') as string;
        cy.fillField('Code reçu par email', code);
      });
      cy.tap('Se connecter', 'ion-button');
      cy.location('pathname').should('eq', '/tabs/list');
    });
  });
});
