import type { Directive } from 'vue';

/**
 * `v-press` on a clickable element that isn't a button (ion-chip renders a
 * plain div): announced as a button, reachable with Tab, activated with
 * Enter or Space — like a native button (spec §9, accessibilité).
 */
export const press: Directive<HTMLElement> = {
  mounted(el) {
    if (!el.hasAttribute('role')) el.setAttribute('role', 'button');
    if (!el.hasAttribute('tabindex')) el.setAttribute('tabindex', '0');
    el.addEventListener('keydown', onKeydown);
  },
  unmounted(el) {
    el.removeEventListener('keydown', onKeydown);
  },
};

function onKeydown(event: KeyboardEvent): void {
  if ('Enter' !== event.key && ' ' !== event.key) return;
  event.preventDefault();
  (event.currentTarget as HTMLElement).click();
}
