<template>
  <ion-page>
    <ion-header>
      <ion-toolbar>
        <ion-title>{{ t('onboarding.title') }}</ion-title>
      </ion-toolbar>
    </ion-header>
    <ion-content class="ion-padding">
      <form @submit.prevent="submit">
        <ion-list>
          <ion-item>
            <ion-input
              v-model="displayName"
              :label="t('onboarding.pseudoLabel')"
              :helper-text="t('onboarding.pseudoHint')"
              label-placement="stacked"
              :minlength="2"
              :maxlength="30"
              required
            />
          </ion-item>
        </ion-list>

        <h3>{{ t('onboarding.birthdayTitle') }}</h3>
        <ion-list>
          <ion-item>
            <ion-input v-model.number="birthDay" :label="t('onboarding.birthDay')" label-placement="stacked" type="number" :min="1" :max="31" />
          </ion-item>
          <ion-item>
            <ion-input v-model.number="birthMonth" :label="t('onboarding.birthMonth')" label-placement="stacked" type="number" :min="1" :max="12" />
          </ion-item>
          <ion-item>
            <ion-input v-model.number="birthYear" :label="t('onboarding.birthYear')" label-placement="stacked" type="number" />
          </ion-item>
        </ion-list>

        <ion-text color="danger" v-if="error">
          <p>{{ error }}</p>
        </ion-text>

        <ion-button expand="block" type="submit" class="ion-margin-top" :disabled="loading">
          {{ t('onboarding.submit') }}
        </ion-button>
      </form>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { IonButton, IonContent, IonHeader, IonInput, IonItem, IonList, IonPage, IonText, IonTitle, IonToolbar } from '@ionic/vue';
import { useAuthStore } from '../stores/auth';
import { useErrorMessage } from '../composables/useErrorMessage';

const { t } = useI18n();
const router = useRouter();
const auth = useAuthStore();
const { describe } = useErrorMessage();

const displayName = ref('');
const birthDay = ref<number | null>(null);
const birthMonth = ref<number | null>(null);
const birthYear = ref<number | null>(null);
const loading = ref(false);
const error = ref('');

async function submit(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    await auth.updateProfile({
      displayName: displayName.value,
      birthDay: birthDay.value,
      birthMonth: birthMonth.value,
      birthYear: birthYear.value,
    });
    router.replace('/tabs/list');
  } catch (e) {
    error.value = describe(e);
  } finally {
    loading.value = false;
  }
}
</script>
