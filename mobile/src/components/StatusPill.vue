<template>
  <span class="status-pill" :class="`status-pill--${variant}`">
    <ion-icon v-if="icon" :icon="icon" aria-hidden="true" />
    <slot />
  </span>
</template>

<script setup lang="ts">
import { IonIcon } from '@ionic/vue';

/**
 * DESIGN.md § 6. `suggestion` is the dashed plum pill — secret
 * convention, friend view only; `draft` the neutral "visible de vous
 * seul" one; `success` for "réservé", `secret` (plum) for a contribution.
 */
withDefaults(defineProps<{ variant?: 'neutral' | 'suggestion' | 'draft' | 'success' | 'secret'; icon?: string }>(), {
  variant: 'neutral',
  icon: undefined,
});
</script>

<style scoped>
.status-pill {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  min-height: 28px;
  padding: 0 10px;
  border-radius: 999px;
  font-size: 13px;
  font-weight: 600;
  white-space: nowrap;
}

.status-pill ion-icon {
  font-size: 15px;
}

.status-pill--neutral {
  background: var(--ic-surface-muted);
  color: var(--ic-text-secondary);
}

.status-pill--suggestion {
  border: 1px dashed var(--ic-secret-border);
  color: var(--ion-color-secret);
}

.status-pill--draft {
  border: 1px dashed var(--ic-border);
  color: var(--ic-text-secondary);
}

.status-pill--secret {
  background: var(--ic-secret-soft);
  color: var(--ion-color-secret);
}

.status-pill--success {
  background: var(--ic-success-soft);
  color: var(--ion-color-success);
}
</style>
