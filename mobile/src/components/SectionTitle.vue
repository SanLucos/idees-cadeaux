<template>
  <div class="section-title" :class="`section-title--${variant}`">
    <h2>
      <ion-icon v-if="icon" :icon="icon" aria-hidden="true" />
      <slot />
    </h2>
    <span v-if="$slots.end" class="section-title__end"><slot name="end" /></span>
  </div>
</template>

<script setup lang="ts">
import { IonIcon } from '@ionic/vue';

withDefaults(defineProps<{ icon?: string; variant?: 'default' | 'secret' | 'private' }>(), {
  icon: undefined,
  variant: 'default',
});
</script>

<style scoped>
.section-title {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
  margin: 20px 0 10px;
}

h2 {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0;
  font-family: var(--ic-font-body);
  font-size: 13px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

h2 ion-icon {
  font-size: 18px;
}

.section-title--secret h2 {
  color: var(--ion-color-secret);
}

.section-title--private h2 {
  color: var(--ic-text-secondary);
}

.section-title__end {
  font-size: 13px;
  color: var(--ic-text-secondary);
}
</style>
