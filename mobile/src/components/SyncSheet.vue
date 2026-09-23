<template>
  <ion-header>
    <ion-toolbar>
      <ion-title>{{ t('sync.title') }}</ion-title>
      <ion-buttons slot="end">
        <ion-button @click="modalController.dismiss()">{{ t('common.close') }}</ion-button>
      </ion-buttons>
    </ion-toolbar>
  </ion-header>
  <ion-content>
    <p class="ic-muted state">
      {{ t(`sync.state.${sync.state}`, { count: sync.pending.length }, sync.pending.length) }}
      <template v-if="sync.lastSyncedAt"> · {{ t('sync.lastSynced', { time: time(sync.lastSyncedAt) }) }}</template>
    </p>

    <template v-if="sync.failed.length">
      <SectionTitle>{{ t('sync.refusedTitle') }}</SectionTitle>
      <div v-for="op in sync.failed" :key="op.seq" class="ic-card op op--failed">
        <div class="op__text">
          <strong>{{ kindLabel(op) }}</strong>
          <span>{{ reason(op) }}</span>
        </div>
        <div class="op__actions">
          <ion-button size="small" class="ic-button-surface" @click="sync.retry(op.seq)">{{ t('sync.retry') }}</ion-button>
          <ion-button size="small" fill="clear" color="danger" @click="sync.discard(op.seq)">{{ t('sync.discard') }}</ion-button>
        </div>
      </div>
      <p class="ic-muted hint">{{ t('sync.discardHint') }}</p>
    </template>

    <template v-if="sync.pending.length">
      <SectionTitle>{{ t('sync.pendingTitle') }}</SectionTitle>
      <div v-for="op in sync.pending" :key="op.seq" class="ic-card op">
        <div class="op__text">
          <strong>{{ kindLabel(op) }}</strong>
          <span class="ic-muted">{{ time(op.createdAt) }}</span>
        </div>
      </div>
    </template>

    <EmptyState v-if="!sync.failed.length && !sync.pending.length">{{ t('sync.nothingPending') }}</EmptyState>

    <ion-button expand="block" :disabled="!sync.online || sync.running" @click="sync.run(true)">{{ t('sync.now') }}</ion-button>
  </ion-content>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { IonButton, IonButtons, IonContent, IonHeader, IonTitle, IonToolbar, modalController } from '@ionic/vue';
import { useSync } from '../offline/sync';
import type { OutboxOp } from '../offline/localStore';
import EmptyState from './EmptyState.vue';
import SectionTitle from './SectionTitle.vue';

/** Spec §8: pending actions, sync errors "avec possibilité de réessayer". */
const { t, te, locale } = useI18n();
const sync = useSync();

const time = (iso: string) => new Intl.DateTimeFormat(locale.value, { hour: '2-digit', minute: '2-digit', day: 'numeric', month: 'short' }).format(new Date(iso));

/** Kinds are dotted ("reservation.create"); i18n keys use "_" (a dot would mean nesting). */
function kindLabel(op: OutboxOp): string {
  return t(`sync.kinds.${op.label.kind.replace(/\./g, '_')}`, { title: op.label.title ?? '' });
}

function reason(op: OutboxOp): string {
  const key = `errors.${op.error?.code}`;

  return te(key) ? t(key, op.error?.extra ?? {}) : t('errors.generic');
}
</script>

<style scoped>
.state {
  margin: 16px 0 0;
}

.op {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-bottom: 10px;
  padding: 12px 14px;
}

.op--failed {
  border-color: var(--ion-color-danger);
}

.op__text {
  display: flex;
  flex-direction: column;
  gap: 2px;
  font-size: 15px;
}

.op__actions {
  display: flex;
  gap: 8px;
}

.op__actions ion-button {
  margin: 0;
}

.hint {
  margin: 0 0 12px;
  font-size: 13px;
}
</style>
