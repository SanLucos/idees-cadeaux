<template>
  <!-- Always says whose app this is right now (spec §11 décision 64); a switcher when I manage children. -->
  <button
    v-if="canSwitch"
    type="button"
    class="profile-greeting profile-greeting--switch"
    :aria-label="t('profileGreeting.switch', { name: currentName })"
    aria-haspopup="menu"
    @click="choose"
  >
    <span>{{ t('myList.hello', { name: currentName }) }}</span>
    <ion-icon :icon="chevronDown" aria-hidden="true" />
  </button>
  <span v-else class="profile-greeting">{{ t('myList.hello', { name: currentName }) }}</span>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { IonIcon } from '@ionic/vue';
import { chevronDown } from 'ionicons/icons';
import { useProfileSwitcher } from '../composables/useProfileSwitcher';

const { t } = useI18n();
const { canSwitch, currentName, choose } = useProfileSwitcher();
</script>

<style scoped>
.profile-greeting {
  font: inherit;
  color: inherit;
}

.profile-greeting--switch {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 44px;
  margin: -12px 0;
  padding: 0 12px;
  margin-inline-start: -12px;
  border: 0;
  border-radius: 999px;
  background: transparent;
  cursor: pointer;
  font-weight: 600;
  color: var(--ion-text-color);
}

.profile-greeting--switch:hover,
.profile-greeting--switch:focus-visible {
  background: rgba(var(--ion-text-color-rgb), 0.06);
}
</style>
