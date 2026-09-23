<template>
  <div class="secret-band" role="note">
    <ion-icon :icon="eyeOffOutline" aria-hidden="true" />
    <span><slot /></span>
  </div>
</template>

<script setup lang="ts">
import { IonIcon } from '@ionic/vue';
import { eyeOffOutline } from 'ionicons/icons';
</script>

<style scoped>
/* DESIGN.md § 3 : bandeau secret, uniquement en vue ami ou gestionnaire. */
.secret-band {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 14px;
  border-radius: var(--ic-radius-card);
  background: var(--ic-secret-soft);
  color: var(--ion-color-secret);
  font-size: 14px;
  font-weight: 600;
  line-height: 1.4;
}

ion-icon {
  flex-shrink: 0;
  font-size: 18px;
}
</style>
