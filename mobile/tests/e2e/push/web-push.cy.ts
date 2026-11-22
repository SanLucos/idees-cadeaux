import type { TestUser } from '../support/commands';

/**
 * Web Push end to end (spec §11 décision 70), as on a phone where the app
 * was installed from Chrome: real Google Chrome, the app's own service
 * worker, Google's push service, the backend's encrypted, signed send.
 * Run with `npm run test:e2e:push` (see cypress.push.config.ts).
 *
 * The last hop, Google's push service to this Chrome, needs a Chrome that
 * Google delivers to: a fresh test profile on Linux often is not
 * (chrome logs GCM « DEPRECATED_ENDPOINT », the subscription is soon 410).
 * So the server side is checked with `app:push:test` (Google accepted the
 * push: 201), and the notification shown on screen only with
 * CYPRESS_PUSH_DELIVERY=1.
 */

/** bin/console app:push:test, in the stack's php container: what an operator sees. */
function pushTest(email: string): Cypress.Chainable<string> {
  return cy.exec(`docker compose -f ../docker-compose.yaml exec -T php bin/console app:push:test ${email}`, { failOnNonZeroExit: false }).its('stdout');
}

type Registration = ServiceWorkerRegistration;

/** The app's service worker, once active (registered by main.ts in a build). */
function worker(): Cypress.Chainable<Registration> {
  return cy.window().then({ timeout: 20000 }, (win) => win.navigator.serviceWorker.ready);
}

/** Retries `probe` until `ok`, the way a phone waits for its push. */
function waitFor<T>(label: string, probe: () => Cypress.Chainable<T>, ok: (value: T) => boolean, tries = 40): Cypress.Chainable<T> {
  return probe().then((value) => {
    if (ok(value)) return cy.wrap(value, { log: false });
    if (tries <= 1) throw new Error(`Timed out waiting for ${label}`);
    cy.wait(500, { log: false });

    return waitFor(label, probe, ok, tries - 1);
  });
}

function shownNotifications(): Cypress.Chainable<{ title: string; body: string; data: Record<string, string> }[]> {
  return worker().then((registration) =>
    registration.getNotifications().then((list) => list.map((n) => ({ title: n.title, body: n.body, data: n.data as Record<string, string> }))),
  );
}

function turnPushOn(): void {
  cy.visit('/settings/notifications');
  cy.contains('ion-toggle', 'Notifications push').should('not.have.attr', 'aria-checked', 'true').click();
  cy.contains('ion-toggle', 'Notifications push').should('have.attr', 'aria-checked', 'true');
}

describe('Web Push in Chrome (spec §11 décision 70)', () => {
  before(() => {
    expect(Cypress.browser.name, 'run in Google Chrome: npm run test:e2e:push').to.equal('chrome');
  });

  // The permission the user gives on the phone, given in advance: the
  // browser's own prompt cannot be clicked by a test.
  beforeEach(() => {
    cy.wrap(
      Cypress.automation('remote:debugger:protocol', {
        command: 'Browser.grantPermissions',
        params: { permissions: ['notifications'], origin: new URL(Cypress.config('baseUrl')!).origin },
      }),
      { log: false },
    );
  });

  it('the server is configured: it gives a VAPID public key', () => {
    cy.createUser('Keys').then((user: TestUser) => {
      cy.apiAs(user, 'GET', '/web-push/public-key').then((response) => {
        expect(response.status).to.equal(200);
        expect(response.body.publicKey, 'VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY of the backend').to.match(/^[A-Za-z0-9_-]{80,}$/);
      });
    });
  });

  it('turning push on subscribes this browser, registers it on the server, and Google accepts its push', () => {
    cy.createUser('Pia').then((pia: TestUser) => {
      cy.loginUi(pia);
      worker().its('active').should('exist');
      cy.window().its('Notification.permission').should('equal', 'granted');

      turnPushOn();
      waitFor('the push subscription', () => worker().then((r) => r.pushManager.getSubscription()), (sub) => null !== sub).then((sub) => {
        expect(sub!.endpoint, 'a subscription of Google’s push service').to.match(/^https:\/\/fcm\.googleapis\.com\//);
      });

      pushTest(pia.email).then((out) => {
        expect(out).to.contain('Web Push (VAPID): configured');
        expect(out).to.match(/Push consent: granted on/);
        expect(out).to.match(/web\s+fcm\.googleapis\.com\s+201\s+sent/);
      });
    });
  });

  it('turning push off removes this device from the server', () => {
    cy.createUser('Olga').then((olga: TestUser) => {
      cy.loginUi(olga);
      turnPushOn();
      waitFor('the push subscription', () => worker().then((r) => r.pushManager.getSubscription()), (sub) => null !== sub);
      pushTest(olga.email).should('match', /Devices \(1\)/);

      cy.contains('ion-toggle', 'Notifications push').click();
      cy.contains('ion-toggle', 'Notifications push').should('have.attr', 'aria-checked', 'false');
      waitFor('the device removed', () => pushTest(olga.email), (out) => out.includes('No device registered'), 20);
    });
  });

  // Needs a Chrome that Google's push service delivers to (see above).
  (Cypress.env('PUSH_DELIVERY') ? it : it.skip)('a friend request is shown by the service worker', () => {
    cy.createUser('Nina').then((nina: TestUser) => {
      cy.createUser('Ravi').then((ravi: TestUser) => {
        cy.loginUi(nina);
        turnPushOn();
        waitFor('the push subscription', () => worker().then((r) => r.pushManager.getSubscription()), (sub) => null !== sub);

        // Ravi asks Nina to be friends: the worker sends the push.
        cy.apiAs(ravi, 'POST', '/friendships', { email: nina.email }).its('status').should('be.oneOf', [200, 201]);
        waitFor('the notification', shownNotifications, (list) => list.length > 0, 60).then((list) => {
          expect(list[0].body).to.equal("Ravi vous a envoyé une demande d'ami.");
          expect(list[0].data.type).to.equal('friend_request_received');
        });
      });
    });
  });
});
