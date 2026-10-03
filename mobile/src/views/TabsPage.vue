<template>
  <ion-page>
    <ion-tabs>
      <ion-router-outlet />
      <ion-tab-bar slot="bottom">
        <ion-tab-button tab="list" href="/tabs/list">
          <ion-icon :icon="giftOutline" aria-hidden="true" />
          <ion-label>{{ t('nav.myList') }}</ion-label>
        </ion-tab-button>
        <ion-tab-button tab="friends" href="/tabs/friends">
          <ion-icon :icon="peopleOutline" aria-hidden="true" />
          <ion-label>{{ t('nav.friends') }}</ion-label>
        </ion-tab-button>

        <!-- Room for the « + » button, which sits outside the tab list (only tabs belong in a tablist). -->
        <div class="tab-add-space" aria-hidden="true" />

        <ion-tab-button tab="activity" href="/tabs/activity">
          <ion-icon :icon="notificationsOutline" aria-hidden="true" />
          <ion-label>{{ t('nav.activity') }}</ion-label>
          <ion-badge v-if="notifications.unreadCount" :aria-label="t('activity.unreadCount', { count: notifications.unreadCount })">
            {{ notifications.unreadCount > 99 ? '99+' : notifications.unreadCount }}
          </ion-badge>
        </ion-tab-button>
        <ion-tab-button tab="profile" href="/tabs/profile">
          <ion-icon :icon="personOutline" aria-hidden="true" />
          <ion-label>{{ t('nav.profile') }}</ion-label>
        </ion-tab-button>
      </ion-tab-bar>
    </ion-tabs>
    <ion-button class="tab-add" shape="round" :aria-label="t('nav.addIdea')" router-link="/ideas/new">
      <ion-icon aria-hidden="true" slot="icon-only" :icon="add" />
    </ion-button>
  </ion-page>
</template>

<script setup lang="ts">
import { onMounted, onUnmounted, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { App } from '@capacitor/app';
import type { PluginListenerHandle } from '@capacitor/core';
import { IonBadge, IonButton, IonIcon, IonLabel, IonPage, IonRouterOutlet, IonTabBar, IonTabButton, IonTabs, toastController } from '@ionic/vue';
import { useSync } from '../offline/sync';
import { startSyncLoop, stopSyncLoop } from '../offline/runtime';
import { useNotificationsStore } from '../stores/notifications';
import { push } from '../services/push';
import { usePushConsent } from '../composables/usePushConsent';
import { add, giftOutline, notificationsOutline, peopleOutline, personOutline } from 'ionicons/icons';

const { t, te } = useI18n();
const sync = useSync();
const notifications = useNotificationsStore();
const pushConsent = usePushConsent();
let resumeListener: PluginListenerHandle | null = null;

// « Déjà réservé par X »… : a queued write the server refused (spec §5.7, §8).
watch(
  () => sync.lastRefusal,
  async (op) => {
    if (!op?.error) return;
    const key = `errors.${op.error.code}`;
    const message = te(key) ? t(key, op.error.extra) : t('sync.refused', { title: op.label.title ?? '' });
    await (await toastController.create({ message, duration: 4000, color: 'danger' })).present();
  },
);

onMounted(async () => {
  void startSyncLoop();
  notifications.startPolling();
  // Spec §5.11: the push token is (re)registered at each sign-in / start.
  void push.register(pushConsent.onOpen);
  resumeListener = await App.addListener('resume', () => void notifications.refreshCount());
});

onUnmounted(() => {
  void stopSyncLoop();
  notifications.stopPolling();
  void resumeListener?.remove();
});
</script>

<style scoped>
ion-tab-bar {
  --border: 1px solid var(--ic-border);
  height: 64px;
  overflow: visible;
  contain: none;
}

ion-tab-button {
  --ripple-color: transparent;
  /* Grows with the system text size, up to a cap (as iOS does for its own tab bars). */
  font-size: min(0.6875rem, 14px);
  font-weight: 600;
  overflow: visible;
}

ion-tab-button.tab-selected {
  font-weight: 700;
}

ion-tab-button ion-badge {
  --background: var(--ion-color-primary);
  --color: var(--ion-color-primary-contrast);
  min-width: 18px;
  font-size: min(0.6875rem, 14px);
}

ion-tab-button ion-icon {
  font-size: min(1.375rem, 28px);
}

.tab-add-space {
  flex: 0 0 72px;
}

/* Over the middle of the tab bar (64px high), overflowing it by 30px. */
.tab-add {
  position: absolute;
  left: 50%;
  bottom: calc(var(--ion-safe-area-bottom, 0px) + 38px);
  transform: translateX(-50%);
  z-index: 10;
  --border-radius: 28px;
  --padding-start: 0;
  --padding-end: 0;
  --box-shadow: 0 6px 16px rgba(var(--ion-color-primary-rgb), 0.35);
  width: 56px;
  height: 56px;
  margin: 0;
  font-size: 26px;
}
</style>
