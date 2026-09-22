<template>
  <div>
    <p class="ion-text-center ion-color-medium">{{ t('auth.login.orSocial') }}</p>
    <ion-button expand="block" fill="outline" @click="loginWith('google')">
      {{ t('auth.social.google') }}
    </ion-button>
    <ion-button expand="block" fill="outline" @click="loginWith('apple')">
      {{ t('auth.social.apple') }}
    </ion-button>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { IonButton } from '@ionic/vue';
import { useAuthStore } from '../stores/auth';
import { getAppleIdToken, getGoogleIdToken } from '../services/socialAuth';

const { t } = useI18n();
const auth = useAuthStore();

const emit = defineEmits<{ error: [message: string] }>();

async function loginWith(provider: 'google' | 'apple'): Promise<void> {
  try {
    const idToken = await ('google' === provider ? getGoogleIdToken() : getAppleIdToken());
    await auth.socialLogin(provider, idToken);
  } catch {
    emit('error', t('auth.social.notConfigured', { provider: 'google' === provider ? 'Google' : 'Apple' }));
  }
}
</script>
