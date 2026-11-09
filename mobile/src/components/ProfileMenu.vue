<template>
  <!-- The « Profil actif » choice, under whatever opened it (spec §11 décision 64). -->
  <ion-list lines="none" class="profile-menu">
    <ion-item
      v-for="profile in profiles"
      :key="profile.id ?? 'me'"
      button
      :detail="false"
      :aria-current="profile.current ? 'true' : undefined"
      :class="{ 'profile-menu__item--current': profile.current }"
      :style="{ '--background': profile.tintColor }"
      @click="pick(profile.id)"
    >
      <AppAvatar slot="start" :id="profile.avatarId" :name="profile.name" :url="profile.avatarUrl" :size="32" :primary="null === profile.id" />
      <ion-label>{{ profile.name }}</ion-label>
      <!-- A fixed column, so every name lines up whichever profile is active. -->
      <span slot="end" class="profile-menu__check">
        <ion-icon v-if="profile.current" :icon="checkmark" color="primary" :aria-label="t('profileGreeting.current')" />
      </span>
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
/* Each line wears the background its profile gives the app (décision 64). */
.profile-menu {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 6px;
  background: var(--ic-surface);
}

.profile-menu ion-item {
  --min-height: 52px;
  --padding-start: 10px;
  --inner-padding-end: 10px;
  --border-radius: 12px;
  --background-hover: var(--ion-text-color);
  --background-hover-opacity: 0.05;
  border-radius: 12px;
  border: 1px solid var(--ic-border);
}

.profile-menu ion-item::part(native) {
  border-radius: 12px;
}


.profile-menu ion-item .app-avatar {
  margin-inline-end: 12px;
}

.profile-menu ion-item.profile-menu__item--current {
  border-color: var(--ion-color-primary);
  font-weight: 600;
}

.profile-menu__check {
  display: inline-flex;
  justify-content: center;
  width: 22px;
  margin-inline-start: 8px;
  font-size: 22px;
}
</style>
