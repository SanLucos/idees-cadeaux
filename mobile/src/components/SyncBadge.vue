<template>
  <button type="button" class="sync-badge" :class="`sync-badge--${sync.state}`" :aria-label="label" @click="open">
    <ion-icon :icon="icon" aria-hidden="true" :class="{ spin: 'syncing' === sync.state }" />
    <span>{{ label }}</span>
  </button>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { IonIcon, modalController } from '@ionic/vue';
import { alertCircleOutline, cloudDoneOutline, cloudOfflineOutline, cloudUploadOutline, syncOutline } from 'ionicons/icons';
import { useSync } from '../offline/sync';
import SyncSheet from './SyncSheet.vue';

/** DESIGN.md « SyncBadge » / spec §8 "indicateurs d'UI". Tap: pending and refused actions. */
const { t } = useI18n();
const sync = useSync();

const icon = computed(
  () => ({ synced: cloudDoneOutline, syncing: syncOutline, pending: cloudUploadOutline, offline: cloudOfflineOutline, error: alertCircleOutline })[sync.state],
);
const label = computed(() => t(`sync.state.${sync.state}`, { count: sync.pending.length }, sync.pending.length));

async function open(): Promise<void> {
  const modal = await modalController.create({ component: SyncSheet, breakpoints: [0, 0.6, 1], initialBreakpoint: 0.6 });
  await modal.present();
}
</script>

<style scoped>
.sync-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 32px;
  padding: 0 10px;
  border: 0;
  border-radius: 999px;
  font: 600 12px var(--ic-font-body);
  white-space: nowrap;
}

.sync-badge ion-icon {
  font-size: 15px;
}

.sync-badge--synced {
  background: var(--ic-success-soft);
  color: var(--ion-color-success);
}

.sync-badge--syncing,
.sync-badge--pending {
  background: var(--ic-surface-muted);
  color: var(--ic-text-secondary);
}

.sync-badge--offline {
  background: var(--ic-surface-muted);
  color: var(--ion-text-color);
}

.sync-badge--error {
  background: var(--ic-primary-soft);
  color: var(--ion-color-danger);
}

.spin {
  animation: spin 1s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
