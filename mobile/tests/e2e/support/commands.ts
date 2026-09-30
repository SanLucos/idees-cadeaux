/// <reference types="cypress" />

export interface TestUser {
  email: string;
  password: string;
  displayName: string;
  token: string;
  id: string;
}

const PASSWORD = 'correcthorsebattery';
const api = (path: string) => `${Cypress.env('apiUrl')}${path}`;

/** A unique address per run, so journeys never collide with earlier data. */
export function uniqueEmail(name: string): string {
  return `e2e-${name.toLowerCase()}-${Date.now()}-${Cypress._.random(1000, 9999)}@example.com`;
}

declare global {
  // eslint-disable-next-line @typescript-eslint/no-namespace
  namespace Cypress {
    interface Chainable {
      /** The last 6-digit code emailed to this address (Mailpit), waiting for it if needed. */
      mailCode(email: string): Chainable<string>;
      /** The last link to `pathPart` emailed to this address. */
      mailLink(email: string, pathPart: string): Chainable<string>;
      /** A verified, onboarded account made through the API. */
      createUser(name: string): Chainable<TestUser>;
      /** An accepted friendship between two accounts (crossed requests). */
      befriend(a: TestUser, b: TestUser): Chainable<void>;
      /** Authenticated API call as `user`. */
      apiAs(user: TestUser, method: string, path: string, body?: unknown): Chainable<Cypress.Response<any>>;
      /** Signs in through the login screen and waits for « Ma liste ». */
      loginUi(user: TestUser): Chainable<void>;
      /** Types into the ion-input (or ion-textarea) whose label or placeholder contains `label`. */
      fillField(label: string, value: string): Chainable<void>;
      /** Some rendered element shows `text` (Ionic keeps hidden pages in the DOM). */
      see(text: string): Chainable<void>;
      /** No visible element shows `text`. */
      notSee(text: string): Chainable<void>;
      /** Taps the visible button (or item, link, chip) showing `text` or labelled so. */
      tap(text: string, selector?: string): Chainable<void>;
      /** Taps the button of the open ion-alert whose text is `text`. */
      alertButton(text: string): Chainable<void>;
    }
  }
}

function latestMessage(email: string, attempt = 0): Cypress.Chainable<{ ID: string; Snippet: string }> {
  return cy
    .request(`${Cypress.env('mailpitUrl')}/api/v1/search?query=${encodeURIComponent(`to:"${email}"`)}&limit=1`)
    .then((response) => {
      const message = response.body.messages?.[0];
      if (message) return message;
      if (attempt >= 20) throw new Error(`No email reached ${email}`);

      return cy.wait(500).then(() => latestMessage(email, attempt + 1));
    });
}

Cypress.Commands.add('mailCode', (email: string) =>
  latestMessage(email).then((message) => {
    const code = message.Snippet.match(/\b\d{6}\b/)?.[0];
    if (!code) throw new Error(`No code in the email to ${email}: ${message.Snippet}`);

    return code;
  }),
);

// The email comes from the worker, maybe after others: wait until the latest one has the link.
function latestLink(email: string, pathPart: string, attempt = 0): Cypress.Chainable<string> {
  return latestMessage(email).then((message) =>
    cy.request(`${Cypress.env('mailpitUrl')}/api/v1/message/${message.ID}`).then((response) => {
      const link = (response.body.HTML as string).match(new RegExp(`href="([^"]*${pathPart}[^"]*)"`))?.[1];
      if (link) return link.replace(/&amp;/g, '&');
      if (attempt >= 30) throw new Error(`No ${pathPart} link in the emails to ${email}`);

      return cy.wait(500).then(() => latestLink(email, pathPart, attempt + 1));
    }),
  );
}

Cypress.Commands.add('mailLink', (email: string, pathPart: string) => latestLink(email, pathPart));

Cypress.Commands.add('createUser', (name: string) => {
  const email = uniqueEmail(name);
  cy.request('POST', api('/auth/register'), { email, password: PASSWORD, locale: 'fr' });
  cy.mailCode(email).then((code) => cy.request('POST', api('/auth/verify-email'), { email, code }));

  return cy.request('POST', api('/auth/login'), { email, password: PASSWORD }).then((login) => {
    const token = login.body.token as string;

    return cy
      .request({ method: 'PATCH', url: api('/users/me'), headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/merge-patch+json' }, body: { displayName: name } })
      .then((me) => ({ email, password: PASSWORD, displayName: name, token, id: me.body.id as string }));
  });
});

Cypress.Commands.add('apiAs', (user: TestUser, method: string, path: string, body?: unknown) =>
  cy.request({ method, url: api(path), headers: { Authorization: `Bearer ${user.token}` }, body: body as Cypress.RequestBody, failOnStatusCode: false }),
);

Cypress.Commands.add('befriend', (a: TestUser, b: TestUser) => {
  cy.apiAs(a, 'POST', '/friendships', { email: b.email });
  cy.apiAs(b, 'POST', '/friendships', { email: a.email });
});

Cypress.Commands.add('loginUi', (user: TestUser) => {
  cy.visit('/login');
  cy.fillField('Email', user.email);
  cy.fillField('Mot de passe', user.password);
  cy.tap('Se connecter', 'ion-button');
  cy.location('pathname').should('match', /^\/(tabs\/list|onboarding)/);
});

/**
 * Ionic keeps the previous pages in the DOM, not rendered (display: none):
 * only look at what is rendered — even if scrolled out of view, which
 * Cypress's :visible would reject inside ion-content.
 */
const rendered = (el: Element) => el.getClientRects().length > 0;

Cypress.Commands.add('fillField', (label: string, value: string) => {
  cy.get('ion-input, ion-textarea')
    .filter((_, el) => rendered(el))
    // Ionic's `label` is a property, not text nor an attribute.
    .filter((_, el) => {
      const field = el as HTMLElement & { label?: string; placeholder?: string };

      return [field.label, field.placeholder, el.textContent].some((text) => String(text ?? '').includes(label));
    })
    .first()
    .scrollIntoView()
    .find('input, textarea')
    .clear({ force: true })
    .type(value, { force: true });
});

Cypress.Commands.add('tap', (text: string, selector = 'ion-button, ion-item, a, button, ion-chip') => {
  cy.get(selector)
    .filter((_, el) => rendered(el))
    .filter((_, el) => {
      // Ionic moves aria-label from the host to the button inside its shadow root.
      const ariaLabel = el.getAttribute('aria-label') ?? el.shadowRoot?.querySelector('[aria-label]')?.getAttribute('aria-label') ?? '';

      return (el.textContent ?? '').replace(/\s+/g, ' ').includes(text) || ariaLabel === text;
    })
    .last()
    .scrollIntoView()
    .click();
});

Cypress.Commands.add('alertButton', (text: string) => {
  cy.get('ion-alert').should('be.visible').contains('button', text).click();
});

const visibleWith = ($root: JQuery, text: string) =>
  $root.find('*').filter((_, el) => rendered(el) && [...el.childNodes].some((n) => n.nodeType === Node.TEXT_NODE && (n.textContent ?? '').includes(text)));

Cypress.Commands.add('see', (text: string) => {
  cy.get('body').should(($body) => {
    expect(visibleWith($body, text).length, `visible « ${text} »`).to.be.greaterThan(0);
  });
});

Cypress.Commands.add('notSee', (text: string) => {
  cy.get('body').should(($body) => {
    expect(visibleWith($body, text).length, `visible « ${text} »`).to.equal(0);
  });
});
