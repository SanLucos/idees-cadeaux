<template>
  <ion-page>
    <ion-header>
      <ion-toolbar>
        <ion-title>{{ t('auth.verifyEmail.title') }}</ion-title>
      </ion-toolbar>
    </ion-header>
    <ion-content class="ion-padding">
      <p>{{ t('auth.verifyEmail.instructions', { email }) }}</p>

      <form @submit.prevent="submit">
        <ion-list>
          <ion-item>
            <ion-input
              v-model="code"
              :label="t('auth.verifyEmail.code')"
              label-placement="stacked"
              inputmode="numeric"
              :maxlength="6"
              required
            />
          </ion-item>
        </ion-list>

        <ion-text color="danger" v-if="error">
          <p>{{ error }}</p>
        </ion-text>
        <ion-text color="success" v-if="info">
          <p>{{ info }}</p>
        </ion-text>

        <ion-button expand="block" type="submit" class="ion-margin-top" :disabled="loading">
          {{ t('auth.verifyEmail.submit') }}
        </ion-button>
        <ion-button expand="block" fill="clear" :disabled="loading" @click="resend">
          {{ t('auth.verifyEmail.resend') }}
        </ion-button>
      </form>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { IonButton, IonContent, IonHeader, IonInput, IonItem, IonList, IonPage, IonText, IonTitle, IonToolbar } from '@ionic/vue';
import { useAuthStore } from '../../stores/auth';
import { useErrorMessage } from '../../composables/useErrorMessage';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const { describe } = useErrorMessage();

const email = String(route.query.email ?? '');
const code = ref('');
const loading = ref(false);
const error = ref('');
const info = ref('');

async function submit(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    await auth.verifyEmail(email, code.value);
    router.push({ name: 'Login' });
  } catch (e) {
    error.value = describe(e);
  } finally {
    loading.value = false;
  }
}

async function resend(): Promise<void> {
  loading.value = true;
  error.value = '';
  info.value = '';
  try {
    await auth.resendVerification(email);
    info.value = t('auth.verifyEmail.resendSuccess');
  } catch (e) {
    error.value = describe(e);
  } finally {
    loading.value = false;
  }
}
</script>
