<template>
  <div class="app-avatar" :style="style" aria-hidden="true">
    <img v-if="url" :src="url" alt="" />
    <span v-else>{{ letters }}</span>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { colorIndex, initials } from '../utils/colorIndex';

const props = withDefaults(
  defineProps<{
    id: string;
    name: string | null | undefined;
    url?: string | null;
    size?: number;
    /** Terracotta instead of the id-based colour (the current user, as on the mock-ups). */
    primary?: boolean;
  }>(),
  { url: null, size: 48, primary: false },
);

const letters = computed(() => initials(props.name));
const style = computed(() => ({
  width: `${props.size}px`,
  height: `${props.size}px`,
  fontSize: `${Math.round(props.size * 0.4)}px`,
  background: props.primary ? 'var(--ion-color-primary)' : `var(--ic-avatar-${colorIndex(props.id, 6)})`,
}));
</script>

<style scoped>
.app-avatar {
  flex-shrink: 0;
  border-radius: 50%;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--ic-surface);
  font-family: var(--ic-font-display);
}

.app-avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
</style>
