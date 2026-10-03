<template>
  <section class="secret-zone" :aria-label="t('secret.zoneTitle', { name })">
    <h2>
      <ion-icon :icon="eyeOffOutline" aria-hidden="true" />
      {{ t('secret.zoneTitle', { name }) }}
    </h2>
    <slot />
  </section>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { IonIcon } from '@ionic/vue';
import { eyeOffOutline } from 'ionicons/icons';

/**
 * DESIGN.md § 3 "zone secrète". Render it only when the API sent
 * hidden data (friend or manager view): it holds what the server
 * returned, it never hides anything itself.
 */
defineProps<{ name: string | null | undefined }>();

const { t } = useI18n();
</script>

<style scoped>
.secret-zone {
  display: flex;
  flex-direction: column;
  gap: 14px;
  margin: 20px 0 24px;
  padding: 16px;
  border: 1.5px dashed var(--ic-secret-border);
  border-radius: var(--ic-radius-block);
  background: var(--ic-secret-zone);
}

h2 {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0;
  font-family: var(--ic-font-body);
  font-size: 0.8125rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--ion-color-secret);
}
</style>
