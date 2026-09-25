<template>
  <ion-page>
    <ion-content>
      <TopBar default-href="/tabs/profile">{{ t('account.delete.title') }}</TopBar>

      <div class="ic-card warning">
        <p><strong>{{ t('account.delete.graceTitle') }}</strong></p>
        <p>{{ t('account.delete.grace') }}</p>
        <p>{{ t('account.delete.erased') }}</p>
      </div>

      <!-- Spec §5.13: the app first offers to export one's data. -->
      <ion-button class="ic-button-surface" expand="block" router-link="/settings/export">
        <ion-icon slot="start" :icon="downloadOutline" aria-hidden="true" />{{ t('account.delete.exportFirst') }}
      </ion-button>

      <template v-if="children.length">
        <SectionTitle>{{ t('account.delete.childrenTitle') }}</SectionTitle>
        <p class="ic-muted">{{ t('account.delete.childrenIntro') }}</p>
        <div class="ic-stack">
          <div v-for="child in children" :key="child.id" class="ic-card child">
            <div class="child__who">
              <AppAvatar :id="child.id" :name="child.displayName" :url="child.avatarUrl" :size="40" />
              <strong>{{ child.displayName }}</strong>
            </div>
            <div class="child__actions">
              <ion-button class="ic-button-surface" size="small" :router-link="`/profile/children/${child.id}`">{{ t('account.delete.attachEmail') }}</ion-button>
              <ion-button class="ic-button-surface" size="small" :router-link="`/profile/children/${child.id}/export`">{{ t('account.delete.exportChild') }}</ion-button>
            </div>
          </div>
        </div>
      </template>

      <SectionTitle>{{ t('account.delete.confirmTitle') }}</SectionTitle>
      <form class="form" @submit.prevent="submit">
        <ReauthFields v-model="reauth" :has-password="auth.user?.hasPassword ?? true" @error="(e) => (error = describe(e))" />
        <ion-checkbox v-model="understood" label-placement="end" justify="start">{{ t('account.delete.understood') }}</ion-checkbox>
        <ion-text v-if="error" color="danger"><p>{{ error }}</p></ion-text>
        <ion-button color="danger" expand="block" type="submit" :disabled="!reauth || !understood || busy">{{ t('account.delete.submit') }}</ion-button>
      </form>
      <div class="bottom-space" />
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { alertController, IonButton, IonCheckbox, IonContent, IonIcon, IonPage, IonText, useIonRouter } from '@ionic/vue';
import { downloadOutline } from 'ionicons/icons';
import { accountApi, type Reauth } from '../services/account';
import { useAuthStore } from '../stores/auth';
import { useActiveProfileStore } from '../stores/activeProfile';
import { useErrorMessage } from '../composables/useErrorMessage';
import AppAvatar from '../components/AppAvatar.vue';
import ReauthFields from '../components/ReauthFields.vue';
import SectionTitle from '../components/SectionTitle.vue';
import TopBar from '../components/TopBar.vue';

/**
 * « Supprimer mon compte » (spec §5.13): export offered first, each child
 * profile's options (hand it to an email, export it — otherwise it goes
 * with the account), re-authentication and an explicit confirmation.
 * Online only (spec §11 décision 35).
 */
const { t } = useI18n();
const { describe } = useErrorMessage();
const ionRouter = useIonRouter();
const auth = useAuthStore();
const activeProfile = useActiveProfileStore();

const children = computed(() => activeProfile.children.filter((c) => !c.deletionScheduledAt));
const reauth = ref<Reauth | null>(null);
const understood = ref(false);
const busy = ref(false);
const error = ref('');

onMounted(() => void activeProfile.fetchChildren().catch(() => undefined));

async function submit(): Promise<void> {
  if (!reauth.value) return;
  const alert = await alertController.create({
    header: t('account.delete.alertTitle'),
    message: t('account.delete.alertMessage'),
    buttons: [
      { text: t('common.cancel'), role: 'cancel' },
      { text: t('account.delete.submit'), role: 'destructive' },
    ],
  });
  await alert.present();
  if ('destructive' !== (await alert.onDidDismiss()).role) return;

  busy.value = true;
  error.value = '';
  try {
    auth.user = await accountApi.requestDeletion(reauth.value);
    activeProfile.switchTo(null);
    ionRouter.navigate('/account/deletion-scheduled', 'root', 'replace');
  } catch (e) {
    error.value = describe(e);
  } finally {
    busy.value = false;
  }
}
</script>

<style scoped>
.warning {
  padding: 16px;
  margin-bottom: 12px;
  border-color: var(--ion-color-danger);
}

.warning p {
  margin: 0 0 8px;
  line-height: 1.45;
}

.warning p:last-child {
  margin-bottom: 0;
}

.child {
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.child__who {
  display: flex;
  align-items: center;
  gap: 10px;
}

.child__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.child__actions ion-button {
  margin: 0;
}

.form {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.bottom-space {
  height: 32px;
}
</style>
