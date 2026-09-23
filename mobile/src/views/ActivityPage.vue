<template>
  <ion-page>
    <ion-content>
      <ScreenHeader>{{ t('activity.title') }}</ScreenHeader>
      <EmptyState :icon="notificationsOutline">{{ t('activity.empty') }}</EmptyState>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { IonContent, IonPage } from '@ionic/vue';
import { notificationsOutline } from 'ionicons/icons';
import ScreenHeader from '../components/ScreenHeader.vue';
import EmptyState from '../components/EmptyState.vue';

const { t } = useI18n();
</script>
