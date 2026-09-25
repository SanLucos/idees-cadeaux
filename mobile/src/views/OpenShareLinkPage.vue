<template>
  <ion-page>
    <ion-content>
      <TopBar :default-href="auth.isAuthenticated ? '/tabs/friends' : '/login'">{{ t('shareLink.open.title') }}</TopBar>
      <p class="ic-muted intro">{{ t('shareLink.open.intro') }}</p>

      <form class="form" @submit.prevent="submit">
        <ion-input
          v-model="input"
          class="ic-field"
          fill="outline"
          type="url"
          inputmode="url"
          autocomplete="off"
          :label="t('shareLink.open.field')"
          label-placement="stacked"
          required
        />
        <ion-text v-if="error" color="danger"><p>{{ error }}</p></ion-text>
        <ion-button expand="block" type="submit">{{ t('shareLink.open.submit') }}</ion-button>
      </form>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { IonButton, IonContent, IonInput, IonPage, IonText, useIonRouter } from '@ionic/vue';
import { useAuthStore } from '../stores/auth';
import { parseShareLinkToken } from '../utils/shareLinkToken';
import TopBar from '../components/TopBar.vue';

/**
 * « J'ai un lien d'invitation » (spec §5.16): no deferred deep link, so
 * someone who installed the app from the web page pastes the link here.
 */
const { t } = useI18n();
const ionRouter = useIonRouter();
const auth = useAuthStore();

const input = ref('');
const error = ref('');

function submit(): void {
  const token = parseShareLinkToken(input.value);
  if (!token) {
    error.value = t('shareLink.open.invalid');
    return;
  }
  error.value = '';
  ionRouter.navigate(`/u/${token}`, 'forward', 'push');
}
</script>

<style scoped>
.intro {
  margin: 0 0 16px;
  line-height: 1.4;
}

.form {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
</style>
