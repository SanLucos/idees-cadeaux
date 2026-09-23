<template>
  <ion-header>
    <ion-toolbar>
      <ion-buttons slot="start">
        <ion-button @click="modalController.dismiss(null, 'cancel')">{{ t('common.cancel') }}</ion-button>
      </ion-buttons>
      <ion-title>{{ t('profile.preferences.add') }}</ion-title>
    </ion-toolbar>
  </ion-header>
  <ion-content>
    <form class="form" @submit.prevent="submit">
      <ion-segment v-model="category" class="ic-segment" mode="ios">
        <ion-segment-button v-for="c in CATEGORIES" :key="c" :value="c">
          <ion-label>{{ t(`profile.preferences.category_${c}`) }}</ion-label>
        </ion-segment-button>
      </ion-segment>
      <ion-input v-model="label" class="ic-field" fill="outline" :label="t('profile.preferences.label')" label-placement="stacked" required />
      <ion-input v-model="value" class="ic-field" fill="outline" :label="t('profile.preferences.value')" label-placement="stacked" required />
      <ion-text v-if="error" color="danger"><p>{{ error }}</p></ion-text>
      <ion-button expand="block" type="submit" :disabled="saving">{{ t('common.add') }}</ion-button>
    </form>
  </ion-content>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  IonButton,
  IonButtons,
  IonContent,
  IonHeader,
  IonInput,
  IonLabel,
  IonSegment,
  IonSegmentButton,
  IonText,
  IonTitle,
  IonToolbar,
  modalController,
} from '@ionic/vue';
import { useProfileDetailsStore } from '../stores/profileDetails';
import { useErrorMessage } from '../composables/useErrorMessage';
import type { ProfilePreferenceCategory } from '../types/profile';

const CATEGORIES: ProfilePreferenceCategory[] = ['gout', 'marque', 'autre'];

const { t } = useI18n();
const store = useProfileDetailsStore();
const { describe } = useErrorMessage();

const category = ref<ProfilePreferenceCategory>('gout');
const label = ref('');
const value = ref('');
const error = ref('');
const saving = ref(false);

async function submit(): Promise<void> {
  saving.value = true;
  error.value = '';
  try {
    await store.addPreference(category.value, label.value.trim(), value.value.trim());
    await modalController.dismiss(null, 'saved');
  } catch (e) {
    error.value = describe(e);
  } finally {
    saving.value = false;
  }
}
</script>

<style scoped>
.form {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 16px 0;
}
</style>
