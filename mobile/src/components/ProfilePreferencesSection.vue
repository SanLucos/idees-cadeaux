<template>
  <div>
    <h2>{{ t('profile.preferences.title') }}</h2>

    <ion-list v-if="store.preferences.length">
      <ion-item v-for="pref in store.preferences" :key="pref['@id']">
        <ion-label>
          <h3>{{ pref.label }}</h3>
          <p>{{ t(`profile.preferences.category_${pref.category}`) }} — {{ pref.value }}</p>
        </ion-label>
        <ion-button slot="end" fill="clear" color="danger" @click="store.removePreference(pref)">
          {{ t('common.delete') }}
        </ion-button>
      </ion-item>
    </ion-list>
    <p v-else>{{ t('profile.preferences.empty') }}</p>

    <form @submit.prevent="add">
      <ion-item>
        <ion-select v-model="category" :label="t('profile.preferences.category')" label-placement="stacked">
          <ion-select-option value="gout">{{ t('profile.preferences.category_gout') }}</ion-select-option>
          <ion-select-option value="marque">{{ t('profile.preferences.category_marque') }}</ion-select-option>
          <ion-select-option value="autre">{{ t('profile.preferences.category_autre') }}</ion-select-option>
        </ion-select>
      </ion-item>
      <ion-item>
        <ion-input v-model="label" :label="t('profile.preferences.label')" label-placement="stacked" required />
      </ion-item>
      <ion-item>
        <ion-input v-model="value" :label="t('profile.preferences.value')" label-placement="stacked" required />
      </ion-item>
      <ion-text color="danger" v-if="error"><p>{{ error }}</p></ion-text>
      <ion-button expand="block" fill="outline" type="submit" class="ion-margin-top">
        {{ t('profile.preferences.add') }}
      </ion-button>
    </form>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { IonButton, IonInput, IonItem, IonLabel, IonList, IonSelect, IonSelectOption, IonText } from '@ionic/vue';
import { useProfileDetailsStore } from '../stores/profileDetails';
import { useErrorMessage } from '../composables/useErrorMessage';
import type { ProfilePreferenceCategory } from '../types/profile';

const { t } = useI18n();
const store = useProfileDetailsStore();
const { describe } = useErrorMessage();

const category = ref<ProfilePreferenceCategory>('gout');
const label = ref('');
const value = ref('');
const error = ref('');

onMounted(() => store.fetchPreferences());

async function add(): Promise<void> {
  error.value = '';
  try {
    await store.addPreference(category.value, label.value, value.value);
    label.value = '';
    value.value = '';
  } catch (e) {
    error.value = describe(e);
  }
}
</script>
