<template>
  <ion-page>
    <ion-content>
      <div class="consent">
        <ion-icon :icon="notificationsOutline" class="consent__icon" aria-hidden="true" />
        <h1 class="ic-screen-title">{{ t('consent.title') }}</h1>
        <p class="ic-muted">{{ t('consent.intro') }}</p>

        <ul class="consent__list">
          <li>{{ t('consent.examples.friends') }}</li>
          <li>{{ t('consent.examples.coordination') }}</li>
          <li>{{ t('consent.examples.birthdays') }}</li>
        </ul>
        <p class="consent__secret">
          <ion-icon :icon="eyeOffOutline" aria-hidden="true" />{{ t('consent.secret') }}
        </p>

        <ion-list class="ic-card-list" lines="inset">
          <ion-item>
            <ion-toggle v-model="pushWanted" justify="space-between">
              <div class="label">{{ t('consent.push') }}</div>
              <div class="hint">{{ t('consent.pushHint') }}</div>
            </ion-toggle>
          </ion-item>
          <ion-item>
            <ion-toggle v-model="emailWanted" justify="space-between">
              <div class="label">{{ t('consent.email') }}</div>
              <div class="hint">{{ t('consent.emailHint') }}</div>
            </ion-toggle>
          </ion-item>
        </ion-list>

        <ion-text v-if="denied" color="danger"><p>{{ t('consent.denied') }}</p></ion-text>

        <ion-button expand="block" :disabled="saving" @click="save">{{ t('consent.continue') }}</ion-button>
        <ion-button expand="block" fill="clear" :disabled="saving" @click="done">{{ t('consent.later') }}</ion-button>
        <p class="ic-muted small">{{ t('consent.changeLater') }}</p>
      </div>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { eyeOffOutline, notificationsOutline } from 'ionicons/icons';
import { IonButton, IonContent, IonIcon, IonItem, IonList, IonPage, IonText, IonToggle, useIonRouter } from '@ionic/vue';
import { notificationsApi } from '../services/notifications';
import { usePushConsent } from '../composables/usePushConsent';

/**
 * Spec §5.11 / §5.1 onboarding: our explanation comes first; the
 * system permission prompt only follows a deliberate "yes".
 */
const { t } = useI18n();
const ionRouter = useIonRouter();
const pushConsent = usePushConsent();

const pushWanted = ref(true);
const emailWanted = ref(false);
const saving = ref(false);
const denied = ref(false);

async function save(): Promise<void> {
  saving.value = true;
  denied.value = false;
  try {
    if (emailWanted.value) await notificationsApi.updateSettings({ consents: { email: true } });
    if (pushWanted.value && !(await pushConsent.enable())) {
      denied.value = true;

      return;
    }
    done();
  } finally {
    saving.value = false;
  }
}

function done(): void {
  ionRouter.navigate('/tabs/list', 'root', 'replace');
}
</script>

<style scoped>
.consent {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding-top: calc(var(--ion-safe-area-top, 0px) + 40px);
  padding-bottom: 24px;
}

.consent__icon {
  font-size: 44px;
  color: var(--ion-color-primary);
}

.consent p {
  margin: 0;
  line-height: 1.45;
}

.consent__list {
  margin: 0;
  padding-left: 20px;
  line-height: 1.6;
}

.consent__secret {
  display: flex;
  gap: 8px;
  align-items: flex-start;
  padding: 12px 14px;
  border-radius: var(--ic-radius-card);
  background: var(--ic-secret-soft);
  color: var(--ion-color-secret);
  font-weight: 600;
  font-size: 14px;
}

.consent__secret ion-icon {
  flex-shrink: 0;
  margin-top: 2px;
}

.label {
  font-weight: 700;
}

.hint {
  font-size: 13px;
  color: var(--ic-text-secondary);
  white-space: normal;
}

.small {
  font-size: 13px;
  text-align: center;
}
</style>
