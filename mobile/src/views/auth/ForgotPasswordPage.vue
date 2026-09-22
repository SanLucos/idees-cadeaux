<template>
  <ion-page>
    <ion-header>
      <ion-toolbar>
        <ion-title>{{ t('auth.forgotPassword.title') }}</ion-title>
      </ion-toolbar>
    </ion-header>
    <ion-content class="ion-padding">
      <p>{{ t('auth.forgotPassword.instructions') }}</p>

      <form v-if="!sent" @submit.prevent="submit">
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
        </ion-list>

        <ion-button expand="block" type="submit" class="ion-margin-top" :disabled="loading">
          {{ t('auth.forgotPassword.submit') }}
        </ion-button>
      </form>

      <template v-else>
        <ion-text color="success"><p>{{ t('auth.forgotPassword.sent') }}</p></ion-text>
        <ion-button expand="block" @click="router.push({ name: 'ResetPassword', query: { email } })">
          {{ t('auth.resetPassword.title') }}
        </ion-button>
      </template>

      <div class="ion-text-center ion-margin-top">
        <router-link to="/login">{{ t('auth.forgotPassword.backToLogin') }}</router-link>
      </div>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { IonButton, IonContent, IonHeader, IonInput, IonItem, IonList, IonPage, IonText, IonTitle, IonToolbar } from '@ionic/vue';
import { useAuthStore } from '../../stores/auth';

const { t } = useI18n();
const router = useRouter();
const auth = useAuthStore();

const email = ref('');
const loading = ref(false);
const sent = ref(false);

async function submit(): Promise<void> {
  loading.value = true;
  try {
    await auth.forgotPassword(email.value);
    sent.value = true;
  } finally {
    loading.value = false;
  }
}
</script>
