<template>
  <ion-header>
    <ion-toolbar>
      <ion-title>{{ t('profile.sizes.history.title') }}</ion-title>
      <ion-buttons slot="end">
        <ion-button @click="modalController.dismiss(null, 'cancel')">{{ t('common.close') }}</ion-button>
      </ion-buttons>
    </ion-toolbar>
  </ion-header>
  <ion-content>
    <div class="history">
      <SectionTitle variant="private" :icon="lockClosedOutline">
        {{ current.label }}
        <template #end>{{ t('ideas.visibleToYouOnly') }}</template>
      </SectionTitle>

      <ion-list class="ic-card-list" lines="inset">
        <ion-item v-for="(entry, index) in entries" :key="entry.id ?? entry.since">
          <ion-label class="history-date">
            {{ t('profile.sizes.history.since', { date: formatDate(entry.since) }) }}
            <p v-if="0 === index">{{ t('profile.sizes.history.current') }}</p>
          </ion-label>
          <span slot="end" class="history-value" :class="{ 'history-value--past': index > 0 }">{{ entry.value }}</span>
          <!-- Past values the server knows: the current one stays, a change still queued has no id yet. -->
          <ion-button
            v-if="index > 0 && entry.id"
            slot="end"
            fill="clear"
            color="medium"
            :aria-label="t('profile.sizes.history.remove', { value: entry.value })"
            @click="remove(entry)"
          >
            <ion-icon aria-hidden="true" slot="icon-only" :icon="trashOutline" />
          </ion-button>
        </ion-item>
      </ion-list>
    </div>
  </ion-content>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { lockClosedOutline, trashOutline } from 'ionicons/icons';
import { alertController, IonButton, IonButtons, IonContent, IonHeader, IonIcon, IonItem, IonLabel, IonList, IonTitle, IonToolbar, modalController } from '@ionic/vue';
import { useProfileDetailsStore } from '../stores/profileDetails';
import SectionTitle from './SectionTitle.vue';
import type { ProfileSize, SizeHistoryEntry } from '../types/profile';

/** Every value a size has had — mine or my child's, never a friend's (spec §11 décision 51). */
const props = defineProps<{ size: ProfileSize }>();

const { t, locale } = useI18n();
const store = useProfileDetailsStore();

/** The size as the device holds it now: it changes when a value is removed. */
const current = computed(() => store.sizes.find((s) => s['@id'] === props.size['@id']) ?? props.size);
/** Newest first: the current value on top. */
const entries = computed(() => [...(current.value.history ?? [])].reverse());

const formatDate = (iso: string) => new Intl.DateTimeFormat(locale.value, { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(iso));

async function remove(entry: SizeHistoryEntry): Promise<void> {
  const alert = await alertController.create({
    header: t('profile.sizes.history.removeConfirm.title'),
    message: t('profile.sizes.history.removeConfirm.message', { value: entry.value }),
    buttons: [
      { text: t('common.cancel'), role: 'cancel' },
      { text: t('common.delete'), role: 'destructive', handler: () => void store.removeSizeHistoryEntry(current.value, entry.id!) },
    ],
  });
  await alert.present();
}
</script>

<style scoped>
.history {
  padding: 0 0 16px;
}

.history-date {
  color: var(--ic-text-secondary);
}

.history-value {
  font-weight: 700;
}

.history-value--past {
  font-weight: 400;
  color: var(--ic-text-secondary);
}
</style>
