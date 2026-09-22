<template>
  <div>
    <h2>{{ t('profile.sizes.title') }}</h2>

    <ion-list v-if="store.sizes.length">
      <ion-item v-for="size in store.sizes" :key="size['@id']">
        <ion-label>
          <h3>{{ size.label }}</h3>
          <p>{{ size.value }}<span v-if="size.note"> — {{ size.note }}</span></p>
        </ion-label>
        <ion-button slot="end" fill="clear" color="danger" @click="store.removeSize(size)">
          {{ t('common.delete') }}
        </ion-button>
      </ion-item>
    </ion-list>
    <p v-else>{{ t('profile.sizes.empty') }}</p>

    <form @submit.prevent="add">
      <ion-item>
        <ion-input v-model="label" :label="t('profile.sizes.label')" label-placement="stacked" required />
      </ion-item>
      <ion-item>
        <ion-input v-model="value" :label="t('profile.sizes.value')" label-placement="stacked" required />
      </ion-item>
      <ion-item>
        <ion-input v-model="note" :label="t('profile.sizes.note')" label-placement="stacked" />
      </ion-item>
      <ion-text color="danger" v-if="error"><p>{{ error }}</p></ion-text>
      <ion-button expand="block" fill="outline" type="submit" class="ion-margin-top">
        {{ t('profile.sizes.add') }}
      </ion-button>
    </form>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { IonButton, IonInput, IonItem, IonLabel, IonList, IonText } from '@ionic/vue';
import { useProfileDetailsStore } from '../stores/profileDetails';
import { useErrorMessage } from '../composables/useErrorMessage';

const { t } = useI18n();
const store = useProfileDetailsStore();
const { describe } = useErrorMessage();

const label = ref('');
const value = ref('');
const note = ref('');
const error = ref('');

onMounted(() => store.fetchSizes());

async function add(): Promise<void> {
  error.value = '';
  try {
    await store.addSize(label.value, value.value, note.value || null);
    label.value = '';
    value.value = '';
    note.value = '';
  } catch (e) {
    error.value = describe(e);
  }
}
</script>
