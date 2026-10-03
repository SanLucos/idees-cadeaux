<template>
  <div class="idea-thumb" :style="style" aria-hidden="true">
    <img v-if="url" :src="url" alt="" loading="lazy" />
    <ion-icon aria-hidden="true" v-else :icon="giftOutline" />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { IonIcon } from '@ionic/vue';
import { giftOutline } from 'ionicons/icons';
import { colorIndex } from '../utils/colorIndex';

const props = withDefaults(defineProps<{ id: string; url?: string | null; size?: number }>(), { url: null, size: 64 });

const style = computed(() => {
  const index = colorIndex(props.id, 5);

  return {
    width: `${props.size}px`,
    height: `${props.size}px`,
    background: `var(--ic-thumb-bg-${index})`,
    color: `var(--ic-thumb-fg-${index})`,
  };
});
</script>

<style scoped>
.idea-thumb {
  flex-shrink: 0;
  border-radius: 12px;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.625rem;
}

.idea-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
</style>
