<template>
  <ion-page>
    <ion-header>
      <ion-toolbar>
        <ion-title>{{ t('auth.login.title') }}</ion-title>
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
              label-placement="stacked"
              type="password"
              autocomplete="current-password"
              required
            />
          </ion-item>
        </ion-list>

        <ion-text color="danger" v-if="error">
          <p>{{ error }}</p>
        </ion-text>

        <ion-button expand="block" type="submit" class="ion-margin-top" :disabled="loading">
          {{ t('auth.login.submit') }}
        </ion-button>

        <div class="ion-text-center ion-margin-top">
          <router-link to="/forgot-password">{{ t('auth.login.forgotPassword') }}</router-link>
        </div>
      </form>

      <ion-text class="ion-text-center" style="display: block; margin-top: 2rem;">
        {{ t('auth.login.noAccount') }}
        <router-link to="/register">{{ t('auth.login.createAccount') }}</router-link>
      </ion-text>
      <ion-text class="ion-text-center" style="display: block; margin-top: 0.75rem;">
        <router-link to="/invitation">{{ t('invitation.link') }}</router-link>
      </ion-text>

      <SocialLoginButtons class="ion-margin-top" @error="(message) => (error = message)" />
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import {
  IonButton,
  IonContent,
  IonHeader,
  IonInput,
  IonItem,
  IonList,
  IonPage,
  IonText,
  IonTitle,
  IonToolbar,
} from '@ionic/vue';
import { useAuthStore } from '../../stores/auth';
import { useErrorMessage } from '../../composables/useErrorMessage';
import SocialLoginButtons from '../../components/SocialLoginButtons.vue';

const { t } = useI18n();
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
    await auth.login(email.value, password.value);
    router.replace('/tabs/list');
  } catch (e) {
    error.value = describe(e);
  } finally {
    loading.value = false;
  }
}
</script>
