<template>
  <ion-page>
    <ion-header>
      <ion-toolbar>
        <ion-title>{{ t('auth.register.title') }}</ion-title>
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
              v-model="password"
              :label="t('auth.password')"
              :helper-text="t('auth.register.passwordHint')"
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
          {{ t('auth.register.submit') }}
        </ion-button>
        <p class="ion-text-center privacy">
          <a :href="backendUrl(`/privacy?lang=${locale}`)" target="_blank" rel="noopener">{{ t('account.privacyNotice') }}</a>
        </p>
      </form>

      <ion-text class="ion-text-center" style="display: block; margin-top: 2rem;">
        {{ t('auth.register.haveAccount') }}
        <router-link to="/login">{{ t('auth.register.signIn') }}</router-link>
      </ion-text>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { IonButton, IonContent, IonHeader, IonInput, IonItem, IonList, IonPage, IonText, IonTitle, IonToolbar } from '@ionic/vue';
import { useAuthStore } from '../../stores/auth';
import { useErrorMessage } from '../../composables/useErrorMessage';
import { backendUrl } from '../../utils/backendUrl';

const { t, locale } = useI18n();
const router = useRouter();
const auth = useAuthStore();
const { describe } = useErrorMessage();

const email = ref('');
const password = ref('');
const loading = ref(false);
const error = ref('');

async function submit(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    await auth.register(email.value, password.value, locale.value);
    router.push({ name: 'VerifyEmail', query: { email: email.value } });
  } catch (e) {
    error.value = describe(e);
  } finally {
    loading.value = false;
  }
}
</script>

<style scoped>
.privacy {
  font-size: 0.875rem;
}
</style>
