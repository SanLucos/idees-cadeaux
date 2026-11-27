<template>
  <ion-app>
    <!-- Computer layout (spec §11 décision 72): a side navigation instead of the tab bar. -->
    <ion-split-pane content-id="main-content" :when="showSidebar">
      <ion-menu v-if="desktop" content-id="main-content" menu-id="desktop-nav" class="desktop-menu" :swipe-gesture="false" :disabled="!showSidebar">
        <DesktopSidebar />
      </ion-menu>
      <ion-router-outlet id="main-content" />
    </ion-split-pane>
  </ion-app>
</template>

<script setup lang="ts">
import { computed, watch } from 'vue';
import { useRoute } from 'vue-router';
import { IonApp, IonMenu, IonRouterOutlet, IonSplitPane } from '@ionic/vue';
import DesktopSidebar from './components/DesktopSidebar.vue';
import { useDesktop } from './composables/useDesktop';
import { useActiveProfileStore } from './stores/activeProfile';
import { useAuthStore } from './stores/auth';
import { applyProfileTint, profileTint } from './utils/profileTint';

// The background follows the active profile (spec §11 décision 64).
const activeProfile = useActiveProfileStore();
const tint = computed(() => profileTint(activeProfile.activeId, activeProfile.children.map((c) => c.id)));
watch(tint, (value) => applyProfileTint(value), { immediate: true });

const desktop = useDesktop();
const auth = useAuthStore();
const route = useRoute();

/** Only inside the app proper: not on the sign-in, onboarding or deletion screens. */
const showSidebar = computed(
  () =>
    desktop.value
    && auth.isAuthenticated
    && !!auth.user?.isOnboarded
    && !auth.user?.deletionScheduledAt
    && !!route.meta.requiresOnboarding
    && 'NotificationConsent' !== route.name,
);
</script>
