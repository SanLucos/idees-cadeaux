/**
 * Spec §9 "tailles de texte dynamiques": with the system text size at
 * 200 % (iOS Dynamic Type sets the root font size; every size is in rem),
 * screens stay usable — nothing wider than the screen (horizontal
 * scrollers excepted: chip rows, scrollable segments).
 */
const LARGE = '32px';

function enlargeText(): void {
  cy.document().then((doc) => doc.documentElement.style.setProperty('font-size', LARGE, 'important'));
}

function noHorizontalOverflow(screen: string): void {
  cy.get('body').should(($body) => {
    const width = $body[0].ownerDocument.documentElement.clientWidth;
    const tooWide = [...$body[0].querySelectorAll<HTMLElement>('ion-content *, ion-tab-bar *, ion-footer *')]
      .filter((el) => el.getClientRects().length > 0 && el.getBoundingClientRect().right > width + 1 && !el.closest('.ic-chip-row, ion-segment.segment-scrollable'))
      .map((el) => `${el.tagName.toLowerCase()}.${[...el.classList].join('.')}`);
    expect(tooWide, `${screen}: elements wider than the screen`).to.deep.equal([]);
  });
}

describe('Large text', () => {
  it('keeps the main screens within the screen width', () => {
    cy.createUser('Camille').then((camille) => {
      cy.apiAs(camille, 'POST', '/ideas', { title: 'Casque audio sans fil à réduction de bruit', priceAmount: '180', occasion: 'birthday' }).then(({ body: idea }) => {
        cy.loginUi(camille);
        for (const [screen, path, text] of [
          ['my list', '/tabs/list', 'Casque audio'],
          ['idea', `/ideas/${idea.id}`, 'Casque audio'],
          ['idea form', '/ideas/new', 'Pour qui ?'],
          ['friends', '/tabs/friends', 'Amis'],
          ['profile', '/tabs/profile', 'Partager mon profil'],
        ] as const) {
          cy.visit(path);
          cy.see(text);
          enlargeText();
          cy.wait(300);
          cy.screenshot(`large-text/${screen}`, { capture: 'viewport', overwrite: true });
          noHorizontalOverflow(screen);
        }
      });
    });
  });
});
