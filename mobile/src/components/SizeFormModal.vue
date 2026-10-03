<template>
  <ion-header>
    <ion-toolbar>
      <ion-buttons slot="start">
        <ion-button @click="modalController.dismiss(null, 'cancel')">{{ t('common.cancel') }}</ion-button>
      </ion-buttons>
      <ion-title>{{ t(size ? 'profile.sizes.edit' : 'profile.sizes.add') }}</ion-title>
    </ion-toolbar>
  </ion-header>
  <ion-content>
    <form class="form" @submit.prevent="submit">
      <ion-input v-model="label" class="ic-field" fill="outline" :label="t('profile.sizes.label')" label-placement="stacked" :maxlength="60" required />
      <div class="ic-chip-row" role="group" :aria-label="t('profile.sizes.suggestions')">
        <ion-chip v-press v-for="key in COMMON_SIZE_LABELS" :key="key" class="ic-chip" @click="label = t(`profile.sizes.common.${key}`)">
          {{ t(`profile.sizes.common.${key}`) }}
        </ion-chip>
      </div>
      <ion-input v-model="value" class="ic-field" fill="outline" :label="t('profile.sizes.value')" label-placement="stacked" :maxlength="60" required />
      <ion-input v-model="note" class="ic-field" fill="outline" :label="t('profile.sizes.note')" label-placement="stacked" :maxlength="200" />
      <ion-text v-if="error" color="danger"><p>{{ error }}</p></ion-text>
      <ion-button expand="block" type="submit" :disabled="saving">{{ t(size ? 'common.save' : 'common.add') }}</ion-button>
    </form>
  </ion-content>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { IonButton, IonButtons, IonChip, IonContent, IonHeader, IonInput, IonText, IonTitle, IonToolbar, modalController } from '@ionic/vue';
import { useProfileDetailsStore } from '../stores/profileDetails';
import { useErrorMessage } from '../composables/useErrorMessage';
import type { ProfileSize } from '../types/profile';

/** With `size`, edits that entry; without, adds one. */
const props = defineProps<{ size?: ProfileSize }>();

/** Spec §5.2: common labels offered while typing; free text is still accepted. */
const COMMON_SIZE_LABELS = ['shoe', 'tshirt', 'sweater', 'shirt', 'trousers', 'jeans', 'dress', 'jacket', 'ring', 'gloves', 'beanie'];

const { t } = useI18n();
const store = useProfileDetailsStore();
const { describe } = useErrorMessage();

const label = ref(props.size?.label ?? '');
const value = ref(props.size?.value ?? '');
const note = ref(props.size?.note ?? '');
const error = ref('');
const saving = ref(false);

async function submit(): Promise<void> {
  saving.value = true;
  error.value = '';
  try {
    if (props.size) await store.updateSize(props.size, label.value.trim(), value.value.trim(), note.value.trim() || null);
    else await store.addSize(label.value.trim(), value.value.trim(), note.value.trim() || null);
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
