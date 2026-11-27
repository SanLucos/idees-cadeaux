import { readonly, ref } from 'vue';
import { isWeb } from '../utils/platform';

/** The computer layout (spec §11 décision 72): the web app on a wide screen. */
export const DESKTOP_MEDIA_QUERY = '(min-width: 1024px)';

const desktop = ref(false);
let started = false;

function apply(value: boolean): void {
  desktop.value = value;
  // Read by the theme (src/theme/variables.css) for the page widths.
  if (value) document.documentElement.dataset.desktop = '';
  else delete document.documentElement.dataset.desktop;
}

/**
 * Follows the window width, web only: the iOS/Android apps keep the
 * phone layout, even on a tablet.
 */
function start(): void {
  if (started) return;
  started = true;
  if (!isWeb() || 'function' !== typeof window.matchMedia) return;

  const media = window.matchMedia(DESKTOP_MEDIA_QUERY);
  apply(media.matches);
  media.addEventListener('change', (event) => apply(event.matches));
}

export function useDesktop() {
  start();

  return readonly(desktop);
}
