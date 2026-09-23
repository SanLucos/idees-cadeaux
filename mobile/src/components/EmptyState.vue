<template>
  <div class="empty-state">
    <ion-icon v-if="icon" :icon="icon" aria-hidden="true" />
    <p><slot /></p>
    <slot name="action" />
  </div>
</template>

<script setup lang="ts">
import { IonIcon } from '@ionic/vue';

defineProps<{ icon?: string }>();
</script>

<style scoped>
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 32px 16px;
  text-align: center;
  color: var(--ic-text-secondary);
}

ion-icon {
  font-size: 32px;
}

p {
  margin: 0;
  font-size: 15px;
  line-height: 1.4;
}
</style>
