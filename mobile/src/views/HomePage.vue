<template>
  <ion-page>
    <ion-header :translucent="true">
      <ion-toolbar>
        <ion-title>{{ t('app.name') }}</ion-title>
        <ion-buttons slot="end">
          <ion-button router-link="/profile">{{ t('nav.profile') }}</ion-button>
        </ion-buttons>
      </ion-toolbar>
    </ion-header>

    <ion-content :fullscreen="true">
      <ion-header collapse="condense">
        <ion-toolbar>
          <ion-title size="large">{{ t('home.title') }}</ion-title>
        </ion-toolbar>
      </ion-header>

      <div class="ion-padding">
        <h1>{{ t('home.welcome', { name: auth.user?.displayName ?? '' }) }}</h1>
      </div>

      <ion-list inset>
        <ion-item>
          <ion-label>{{ t('home.apiStatusLabel') }}</ion-label>
          <ion-badge :color="badgeColor" slot="end">{{ statusLabel }}</ion-badge>
        </ion-item>
      </ion-list>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  IonBadge,
  IonButton,
  IonButtons,
  IonContent,
  IonHeader,
  IonItem,
  IonLabel,
  IonList,
  IonPage,
  IonTitle,
  IonToolbar,
} from '@ionic/vue';
import { useApiHealthStore } from '../stores/apiHealth';
import { useAuthStore } from '../stores/auth';

const { t } = useI18n();
const apiHealth = useApiHealthStore();
const auth = useAuthStore();

onMounted(() => {
  apiHealth.check();
});

const statusLabel = computed(() => t(`home.apiStatus${capitalize(apiHealth.status)}`));
const badgeColor = computed(() => {
  if (apiHealth.status === 'ok') return 'success';
  if (apiHealth.status === 'error') return 'danger';
  return 'medium';
});

function capitalize(value: string): string {
  return value.charAt(0).toUpperCase() + value.slice(1);
}
</script>
