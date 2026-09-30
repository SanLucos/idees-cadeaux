import { uniqueEmail } from '../support/commands';

/** Spec §5.1–5.2: sign up, email code, onboarding, then « Ma liste ». */
describe('Account', () => {
  it('signs up, verifies the email and completes onboarding', () => {
    const email = uniqueEmail('Alice');

    cy.visit('/register');
    cy.fillField('Email', email);
    cy.fillField('Mot de passe', 'correcthorsebattery');
    cy.tap("S'inscrire", 'ion-button');

    cy.location('pathname').should('eq', '/verify-email');
    cy.mailCode(email).then((code) => cy.fillField('Code de vérification', code));
    cy.tap('Vérifier', 'ion-button');

    cy.location('pathname').should('eq', '/login');
    cy.fillField('Email', email);
    cy.fillField('Mot de passe', 'correcthorsebattery');
    cy.tap('Se connecter', 'ion-button');

    cy.location('pathname').should('eq', '/onboarding');
    cy.fillField('Pseudo', 'Alice');
    cy.tap('Continuer', 'ion-button');

    cy.location('pathname').should('eq', '/onboarding/notifications');
    cy.tap('Plus tard', 'ion-button');
    cy.location('pathname').should('eq', '/tabs/list');
  });

  it('refuses a wrong password', () => {
    cy.createUser('Bob').then((bob) => {
      cy.visit('/login');
      cy.fillField('Email', bob.email);
      cy.fillField('Mot de passe', 'not-the-password');
      cy.tap('Se connecter', 'ion-button');
      cy.see('Email ou mot de passe incorrect.');
      cy.location('pathname').should('eq', '/login');
    });
  });
});
