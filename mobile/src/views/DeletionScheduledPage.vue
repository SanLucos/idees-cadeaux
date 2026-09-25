<template>
  <ion-page>
    <ion-content>
      <div class="scheduled">
        <ion-icon :icon="timeOutline" color="danger" aria-hidden="true" />
        <h1 class="ic-display">{{ t('account.scheduled.title', { date }) }}</h1>
        <p class="ic-muted">{{ t('account.scheduled.text') }}</p>
        <ion-text v-if="error" color="danger"><p>{{ error }}</p></ion-text>
      </div>
    </ion-content>
    <ion-footer class="ion-no-border">
      <div class="actions">
        <ion-button expand="block" :disabled="busy" @click="cancel">{{ t('account.scheduled.cancel') }}</ion-button>
        <ion-button class="ic-button-surface" expand="block" router-link="/settings/export">{{ t('account.export.title') }}</ion-button>
        <ion-button fill="clear" expand="block" @click="logout">{{ t('nav.logout') }}</ion-button>
      </div>
    </ion-footer>
  </ion-page>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { IonButton, IonContent, IonFooter, IonIcon, IonPage, IonText, useIonRouter } from '@ionic/vue';
import { timeOutline } from 'ionicons/icons';
import { accountApi } from '../services/account';
import { useAuthStore } from '../stores/auth';
import { useErrorMessage } from '../composables/useErrorMessage';

/**
 * « Votre compte sera supprimé le … » (spec §5.13): during the 14 days of
 * grace, the only screen of a signed-in account — cancel, export, sign out.
 */
const { t, locale } = useI18n();
const { describe } = useErrorMessage();
const ionRouter = useIonRouter();
const auth = useAuthStore();

const busy = ref(false);
const error = ref('');
const date = computed(() => {
  const at = auth.user?.deletionScheduledAt;

  return at ? new Date(at).toLocaleDateString(locale.value, { day: 'numeric', month: 'long', year: 'numeric' }) : '';
});

async function cancel(): Promise<void> {
  busy.value = true;
  error.value = '';
  try {
    auth.user = await accountApi.cancelDeletion();
    ionRouter.navigate('/tabs/list', 'root', 'replace');
  } catch (e) {
    error.value = describe(e);
  } finally {
    busy.value = false;
  }
}

async function logout(): Promise<void> {
  await auth.logout();
  ionRouter.navigate('/login', 'root', 'replace');
}
</script>

<style scoped>
.scheduled {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 12px;
  padding-top: calc(var(--ion-safe-area-top, 0px) + 18vh);
}

.scheduled ion-icon {
  font-size: 56px;
}

.scheduled h1 {
  margin: 0;
  font-size: 28px;
  line-height: 1.2;
}

.scheduled p {
  margin: 0;
  line-height: 1.5;
}

.actions {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 12px var(--ic-gutter) calc(var(--ion-safe-area-bottom, 0px) + 16px);
}

.actions ion-button {
  margin: 0;
}
</style>
