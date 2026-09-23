<template>
  <div
    class="progress"
    role="progressbar"
    :aria-valuenow="percent"
    aria-valuemin="0"
    aria-valuemax="100"
  >
    <div class="progress__bar" :style="{ width: `${percent}%` }" />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ total: string; target: string | null }>();

const percent = computed(() => {
  const target = Number(props.target);
  if (!props.target || !target) return 0;

  return Math.min(100, Math.round((Number(props.total) / target) * 100));
});
</script>

<style scoped>
.progress {
  height: 10px;
  border-radius: 999px;
  background: var(--ic-surface-muted);
  overflow: hidden;
}

.progress__bar {
  height: 100%;
  border-radius: 999px;
  background: var(--ion-color-secret);
}
</style>
