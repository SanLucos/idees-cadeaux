import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router';
import { i18n } from './i18n';
import { openLocalDb } from './offline/runtime';
import { listenForShares } from './services/shareIntake';
import { press } from './directives/press';
import { reportError, setErrorRoute } from './services/errorReporter';
import { setOnDeletionScheduled } from './services/api';
import { useAuthStore } from './stores/auth';

import { IonicVue } from '@ionic/vue';

/* Core CSS required for Ionic components to work properly */
import '@ionic/vue/css/core.css';

/* Basic CSS for apps built with Ionic */
import '@ionic/vue/css/normalize.css';
import '@ionic/vue/css/structure.css';
import '@ionic/vue/css/typography.css';

/* Optional CSS utils that can be commented out */
import '@ionic/vue/css/padding.css';
import '@ionic/vue/css/float-elements.css';
import '@ionic/vue/css/text-alignment.css';
import '@ionic/vue/css/text-transformation.css';
import '@ionic/vue/css/flex-utils.css';
import '@ionic/vue/css/display.css';

/**
 * Ionic Dark Mode
 * -----------------------------------------------------
 * For more info, please see:
 * https://ionicframework.com/docs/theming/dark-mode
 */

/* @import '@ionic/vue/css/palettes/dark.always.css'; */
/* @import '@ionic/vue/css/palettes/dark.class.css'; */
// Pas de palette sombre Ionic : l'identité (docs/design) est claire uniquement ;
// un mode sombre se déclinera depuis theme/variables.css le moment venu.
// import '@ionic/vue/css/palettes/dark.system.css';

/* Polices embarquées (pas de CDN : l'appli doit fonctionner hors-ligne) */
import '@fontsource/young-serif/400.css';
import '@fontsource/figtree/400.css';
import '@fontsource/figtree/500.css';
import '@fontsource/figtree/600.css';
import '@fontsource/figtree/700.css';

/* Theme variables */
import './theme/variables.css';

const app = createApp(App).use(IonicVue).use(createPinia()).use(i18n);
app.directive('press', press);

// Spec §9: uncaught errors reach the backend's logs (services/errorReporter).
app.config.errorHandler = (error) => {
  console.error(error);
  reportError(error, 'vue');
};
window.addEventListener('error', (event) => reportError(event.error ?? event.message, 'window'));
window.addEventListener('unhandledrejection', (event) => reportError(event.reason, 'promise'));
// Spec §5.13: the account was scheduled for deletion (e.g. from another
// device): reload it, the router then shows the grace-period screen.
setOnDeletionScheduled(() => {
  void useAuthStore()
    .fetchMe()
    .then(() => router.replace({ name: 'DeletionScheduled' }))
    .catch(() => undefined);
});
// The route's name, not its path: paths may hold share tokens.
router.afterEach((to) => setErrorRoute(String(to.name ?? '')));

// The device database (spec §8) must be open before the first screen —
// and the session restore — read from it.
openLocalDb().then(() => {
  app.use(router);
  router.isReady().then(() => {
    app.mount('#app');
    void listenForShares(router);
  });
});
