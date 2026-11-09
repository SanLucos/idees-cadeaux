<template>
  <ion-app>
    <ion-router-outlet />
  </ion-app>
</template>

<script setup lang="ts">
import { computed, watch } from 'vue';
import { IonApp, IonRouterOutlet } from '@ionic/vue';
import { useActiveProfileStore } from './stores/activeProfile';
import { applyProfileTint, profileTint } from './utils/profileTint';

// The background follows the active profile (spec §11 décision 64).
const activeProfile = useActiveProfileStore();
const tint = computed(() => profileTint(activeProfile.activeId, activeProfile.children.map((c) => c.id)));
watch(tint, (value) => applyProfileTint(value), { immediate: true });
</script>
