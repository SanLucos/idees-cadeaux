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

        <div class="tab-add">
          <ion-button shape="round" :aria-label="t('nav.addIdea')" router-link="/ideas/new">
            <ion-icon slot="icon-only" :icon="add" />
          </ion-button>
        </div>

        <ion-tab-button tab="activity" href="/tabs/activity">
          <ion-icon :icon="notificationsOutline" aria-hidden="true" />
          <ion-label>{{ t('nav.activity') }}</ion-label>
        </ion-tab-button>
        <ion-tab-button tab="profile" href="/tabs/profile">
          <ion-icon :icon="personOutline" aria-hidden="true" />
          <ion-label>{{ t('nav.profile') }}</ion-label>
        </ion-tab-button>
      </ion-tab-bar>
    </ion-tabs>
  </ion-page>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { IonButton, IonIcon, IonLabel, IonPage, IonRouterOutlet, IonTabBar, IonTabButton, IonTabs } from '@ionic/vue';
import { add, giftOutline, notificationsOutline, peopleOutline, personOutline } from 'ionicons/icons';

const { t } = useI18n();
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
  font-size: 11px;
  font-weight: 600;
  overflow: visible;
}

ion-tab-button.tab-selected {
  font-weight: 700;
}

ion-tab-button ion-icon {
  font-size: 22px;
}

.tab-add {
  flex: 0 0 72px;
  display: flex;
  justify-content: center;
}

.tab-add ion-button {
  --border-radius: 28px;
  --padding-start: 0;
  --padding-end: 0;
  --box-shadow: 0 6px 16px rgba(var(--ion-color-primary-rgb), 0.35);
  width: 56px;
  height: 56px;
  margin: -30px 0 0;
  font-size: 26px;
}
</style>
