<template>
  <ion-page>
    <ion-content>
      <TopBar default-href="/tabs/profile">{{ t('notificationSettings.title') }}</TopBar>

      <template v-if="settings">
        <SectionTitle>{{ t('notificationSettings.channels') }}</SectionTitle>
        <ion-list class="ic-card-list" lines="inset">
          <ion-item>
            <ion-toggle :checked="settings.consents.push.granted" justify="space-between" @ion-change="togglePush($event.detail.checked)">
              <div class="label">{{ t('consent.push') }}</div>
              <div class="hint">{{ consentHint('push') }}</div>
            </ion-toggle>
          </ion-item>
          <ion-item>
            <ion-toggle :checked="settings.consents.email.granted" justify="space-between" @ion-change="save({ consents: { email: $event.detail.checked } })">
              <div class="label">{{ t('consent.email') }}</div>
              <div class="hint">{{ consentHint('email') }}</div>
            </ion-toggle>
          </ion-item>
        </ion-list>
        <p class="ic-muted note">{{ t('notificationSettings.inAppAlways') }}</p>

        <SectionTitle>{{ t('notificationSettings.birthdays') }}</SectionTitle>
        <div class="ic-card block">
          <p class="ic-muted">{{ t('notificationSettings.birthdaysHint') }}</p>
          <div class="chips">
            <ion-chip v-for="day in settings.birthdayReminderDays" :key="day" class="ic-chip" @click="removeDelay(day)">
              {{ t('notificationSettings.delay', { days: day }, day) }}
              <ion-icon :icon="closeCircle" :aria-label="t('common.delete')" />
            </ion-chip>
            <ion-chip v-if="settings.birthdayReminderDays.length < 3" class="ic-chip" @click="addDelay">
              <ion-icon :icon="add" aria-hidden="true" />{{ t('notificationSettings.addDelay') }}
            </ion-chip>
          </div>
          <p v-if="!settings.birthdayReminderDays.length" class="ic-muted">{{ t('notificationSettings.birthdaysOff') }}</p>
        </div>

        <SectionTitle>{{ t('notificationSettings.byType') }}</SectionTitle>
        <ion-list class="ic-card-list" lines="inset">
          <ion-item v-for="type in visibleTypes" :key="type">
            <ion-label>
              <div class="label">{{ t(`notificationSettings.types.${type}`) }}</div>
              <div class="channels">
                <ion-chip
                  v-for="channel in CHANNELS"
                  :key="channel"
                  class="ic-chip channel"
                  :class="{ 'ic-chip-selected': settings.preferences[type][channel] }"
                  :aria-pressed="settings.preferences[type][channel]"
                  @click="save({ preferences: { [type]: { [channel]: !settings.preferences[type][channel] } } })"
                >
                  {{ t(`notificationSettings.channel.${channel}`) }}
                </ion-chip>
              </div>
            </ion-label>
          </ion-item>
        </ion-list>
        <div class="bottom-space" />
      </template>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { add, closeCircle } from 'ionicons/icons';
import { alertController, IonChip, IonContent, IonIcon, IonItem, IonLabel, IonList, IonPage, IonToggle, onIonViewWillEnter, toastController } from '@ionic/vue';
import { notificationsApi } from '../services/notifications';
import { usePushConsent } from '../composables/usePushConsent';
import { useErrorMessage } from '../composables/useErrorMessage';
import SectionTitle from '../components/SectionTitle.vue';
import TopBar from '../components/TopBar.vue';
import { NOTIFICATION_TYPES, type NotificationChannel, type NotificationSettings } from '../types/notification';

const CHANNELS: NotificationChannel[] = ['in_app', 'push', 'email'];

const { t, locale } = useI18n();
const { describe } = useErrorMessage();
const pushConsent = usePushConsent();
const settings = ref<NotificationSettings | null>(null);

// Share-link types arrive with lot 7 bis; nothing to tune until then.
const visibleTypes = computed(() => NOTIFICATION_TYPES.filter((type) => !['friend_joined_via_link', 'share_link_suspended'].includes(type)));

onIonViewWillEnter(async () => {
  settings.value = await notificationsApi.settings();
});

async function save(patch: Parameters<typeof notificationsApi.updateSettings>[0]): Promise<void> {
  try {
    settings.value = await notificationsApi.updateSettings(patch);
  } catch (e) {
    await (await toastController.create({ message: describe(e), duration: 3000, color: 'danger' })).present();
    settings.value = await notificationsApi.settings();
  }
}

async function togglePush(on: boolean): Promise<void> {
  if (on === settings.value?.consents.push.granted) return;
  if (on) {
    if (!(await pushConsent.enable())) {
      await (await toastController.create({ message: t('consent.denied'), duration: 3500, color: 'danger' })).present();
    }
  } else {
    await pushConsent.disable();
  }
  settings.value = await notificationsApi.settings();
}

function consentHint(channel: 'push' | 'email'): string {
  const consent = settings.value?.consents[channel];
  if (!consent?.granted || !consent.consentedAt) return t(`consent.${channel}Hint`);

  return t('notificationSettings.consentedOn', { date: new Date(consent.consentedAt).toLocaleDateString(locale.value) });
}

function removeDelay(day: number): Promise<void> {
  return save({ birthdayReminderDays: settings.value!.birthdayReminderDays.filter((d) => d !== day) });
}

async function addDelay(): Promise<void> {
  const alert = await alertController.create({
    header: t('notificationSettings.addDelay'),
    message: t('notificationSettings.addDelayHint'),
    inputs: [{ name: 'days', type: 'number', min: 0, max: 30, value: 7 }],
    buttons: [
      { text: t('common.cancel'), role: 'cancel' },
      { text: t('common.add'), role: 'confirm' },
    ],
  });
  await alert.present();
  const result = await alert.onDidDismiss();
  if ('confirm' !== result.role) return;

  const days = Number(result.data.values.days);
  await save({ birthdayReminderDays: [...settings.value!.birthdayReminderDays, days] });
}
</script>

<style scoped>
.label {
  font-weight: 700;
}

.hint {
  font-size: 13px;
  color: var(--ic-text-secondary);
  white-space: normal;
}

.note {
  margin: 8px 4px 0;
  font-size: 14px;
}

.block {
  padding: 16px;
}

.block p {
  margin: 0 0 10px;
}

.chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.chips ion-icon {
  margin: 0 0 0 4px;
}

.channels {
  display: flex;
  gap: 6px;
  margin-top: 8px;
}

.channel {
  min-height: 30px;
  font-size: 13px;
}

.bottom-space {
  height: 24px;
}
</style>
