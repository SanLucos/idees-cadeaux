<template>
  <div class="reauth">
    <template v-if="hasPassword">
      <ion-input
        v-model="password"
        class="ic-field"
        fill="outline"
        type="password"
        autocomplete="current-password"
        :label="t('account.reauth.password')"
        label-placement="stacked"
      />
    </template>
    <template v-else>
      <p class="ic-muted">{{ codeSent ? t('account.reauth.codeSent') : t('account.reauth.codeIntro') }}</p>
      <ion-button v-if="!codeSent" class="ic-button-surface" expand="block" :disabled="sending" @click="sendCode">{{ t('account.reauth.sendCode') }}</ion-button>
      <ion-input
        v-else
        v-model="code"
        class="ic-field"
        fill="outline"
        inputmode="numeric"
        autocomplete="one-time-code"
        :maxlength="6"
        :label="t('account.reauth.code')"
        label-placement="stacked"
      />
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { IonButton, IonInput } from '@ionic/vue';
import { accountApi, type Reauth } from '../services/account';

/**
 * Spec §5.13 "ré-authentification": the password when the account has
 * one, otherwise a 6-digit code sent by email (Google/Apple accounts).
 * v-model: the credentials to send, or null while incomplete.
 */
const props = defineProps<{ hasPassword: boolean }>();
const model = defineModel<Reauth | null>({ required: true });
const emit = defineEmits<{ error: [error: unknown] }>();

const { t } = useI18n();
const password = ref('');
const code = ref('');
const codeSent = ref(false);
const sending = ref(false);

const value = computed<Reauth | null>(() => {
  if (props.hasPassword) return password.value ? { password: password.value } : null;

  return /^\d{6}$/.test(code.value.trim()) ? { code: code.value.trim() } : null;
});
watch(value, (v) => (model.value = v), { immediate: true });

async function sendCode(): Promise<void> {
  sending.value = true;
  try {
    await accountApi.sendReauthCode();
    codeSent.value = true;
  } catch (e) {
    emit('error', e);
  } finally {
    sending.value = false;
  }
}
</script>

<style scoped>
.reauth {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.reauth p {
  margin: 0;
}
</style>
