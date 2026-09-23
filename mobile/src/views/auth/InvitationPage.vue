<template>
  <ion-page>
    <ion-content>
      <TopBar default-href="/login">{{ t('invitation.title') }}</TopBar>
      <p class="ic-muted intro">{{ t('invitation.intro') }}</p>

      <form class="form" @submit.prevent="submit">
        <ion-input v-model="email" class="ic-field" fill="outline" type="email" autocomplete="email" :label="t('auth.email')" label-placement="stacked" required />
        <ion-input
          v-model="code"
          class="ic-field"
          fill="outline"
          inputmode="numeric"
          autocomplete="one-time-code"
          :maxlength="6"
          :label="t('invitation.code')"
          label-placement="stacked"
          required
        />
        <ion-input
          v-model="password"
          class="ic-field"
          fill="outline"
          type="password"
          autocomplete="new-password"
          :label="t('invitation.password')"
          :helper-text="t('auth.register.passwordHint')"
          label-placement="stacked"
          required
        />
        <ion-text v-if="error" color="danger"><p>{{ error }}</p></ion-text>
        <ion-button expand="block" type="submit" :disabled="loading">{{ t('invitation.submit') }}</ion-button>
      </form>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { IonButton, IonContent, IonInput, IonPage, IonText, useIonRouter } from '@ionic/vue';
import { useAuthStore } from '../../stores/auth';
import { useErrorMessage } from '../../composables/useErrorMessage';
import TopBar from '../../components/TopBar.vue';

/**
 * Spec §5.15 "rattacher un email": whoever received the code takes the
 * child profile over with a password and is signed straight in.
 */
const { t } = useI18n();
const ionRouter = useIonRouter();
const auth = useAuthStore();
const { describe } = useErrorMessage();

const email = ref('');
const code = ref('');
const password = ref('');
const loading = ref(false);
const error = ref('');

async function submit(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    await auth.acceptInvitation(email.value.trim(), code.value.trim(), password.value);
    ionRouter.navigate('/tabs/list', 'root', 'replace');
  } catch (e) {
    error.value = describe(e);
  } finally {
    loading.value = false;
  }
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
