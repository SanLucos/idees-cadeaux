<template>
  <div class="top-bar">
    <ion-button class="ic-round-button" :aria-label="closeIcon ? t('common.close') : t('common.back')" @click="goBack">
      <ion-icon slot="icon-only" :icon="closeIcon ? closeOutline : chevronBackOutline" />
    </ion-button>
    <div class="top-bar__title"><slot /></div>
    <slot name="end" />
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { IonButton, IonIcon, useIonRouter } from '@ionic/vue';
import { chevronBackOutline, closeOutline } from 'ionicons/icons';

const props = withDefaults(defineProps<{ defaultHref: string; closeIcon?: boolean }>(), { closeIcon: false });

const { t } = useI18n();
const ionRouter = useIonRouter();

function goBack(): void {
  if (ionRouter.canGoBack()) {
    ionRouter.back();
  } else {
    ionRouter.navigate(props.defaultHref, 'back', 'pop');
  }
}
</script>

<style scoped>
.top-bar {
  display: flex;
  align-items: center;
  gap: 12px;
  padding-top: calc(var(--ion-safe-area-top, 0px) + 16px);
  padding-bottom: 12px;
}

.top-bar__title {
  flex-grow: 1;
  min-width: 0;
  font-family: var(--ic-font-display);
  font-size: 22px;
}
</style>
