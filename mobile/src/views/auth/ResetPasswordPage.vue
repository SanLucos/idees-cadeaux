<template>
  <ion-page>
    <ion-header>
      <ion-toolbar>
        <ion-title>{{ t('auth.resetPassword.title') }}</ion-title>
      </ion-toolbar>
    </ion-header>
    <ion-content class="ion-padding">
      <form @submit.prevent="submit">
        <ion-list>
          <ion-item>
            <ion-input
              v-model="email"
              :label="t('auth.email')"
              label-placement="stacked"
              type="email"
              autocomplete="email"
              required
            />
          </ion-item>
          <ion-item>
            <ion-input
              v-model="code"
              :label="t('auth.resetPassword.code')"
              label-placement="stacked"
              inputmode="numeric"
              :maxlength="6"
              required
            />
          </ion-item>
          <ion-item>
            <ion-input
              v-model="newPassword"
              :label="t('auth.resetPassword.newPassword')"
              label-placement="stacked"
              type="password"
              autocomplete="new-password"
              required
            />
          </ion-item>
        </ion-list>

        <ion-text color="danger" v-if="error">
          <p>{{ error }}</p>
        </ion-text>

        <ion-button expand="block" type="submit" class="ion-margin-top" :disabled="loading">
          {{ t('auth.resetPassword.submit') }}
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

const email = ref(String(route.query.email ?? ''));
const code = ref('');
const newPassword = ref('');
const loading = ref(false);
const error = ref('');

async function submit(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    await auth.resetPassword(email.value, code.value, newPassword.value);
    router.push({ name: 'Login' });
  } catch (e) {
    error.value = describe(e);
  } finally {
    loading.value = false;
  }
}
</script>
