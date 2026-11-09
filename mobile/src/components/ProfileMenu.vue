<template>
  <!-- The « Profil actif » choice, under whatever opened it (spec §11 décision 64). -->
  <ion-list lines="none" class="profile-menu">
    <ion-item
      v-for="profile in profiles"
      :key="profile.id ?? 'me'"
      button
      :detail="false"
      :aria-current="profile.current ? 'true' : undefined"
      @click="pick(profile.id)"
    >
      <AppAvatar slot="start" :id="profile.avatarId" :name="profile.name" :url="profile.avatarUrl" :size="32" :primary="null === profile.id" />
      <ion-label>{{ profile.name }}</ion-label>
      <span slot="end" class="profile-menu__tint" :style="{ background: profile.tintColor }" aria-hidden="true" />
      <ion-icon v-if="profile.current" slot="end" :icon="checkmark" color="primary" :aria-label="t('profileGreeting.current')" />
    </ion-item>
  </ion-list>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { IonIcon, IonItem, IonLabel, IonList, popoverController } from '@ionic/vue';
import { checkmark } from 'ionicons/icons';
import AppAvatar from './AppAvatar.vue';
import type { ProfileChoice } from '../composables/useProfileSwitcher';

defineProps<{ profiles: ProfileChoice[] }>();

const { t } = useI18n();

function pick(id: string | null): void {
  void popoverController.dismiss({ id }, 'select');
}
</script>

<style scoped>
.profile-menu {
  padding: 4px 0;
}

.profile-menu ion-item {
  --min-height: 48px;
}

.profile-menu__tint {
  width: 14px;
  height: 14px;
  border-radius: 50%;
  border: 1px solid var(--ic-border-field);
}
</style>
