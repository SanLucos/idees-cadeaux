<template>
  <div class="filter-group" role="group" :aria-label="groupLabel">
    <span class="filter-group__label" aria-hidden="true">{{ label }}</span>
    <ion-chip
      v-for="option in options"
      :key="option.value"
      v-press
      class="ic-chip"
      :class="{ 'ic-chip-selected': option.pressed }"
      :aria-pressed="option.pressed"
      :data-filter="option.value"
      @click="emit('toggle', option.value)"
    >
      <ion-icon v-if="multiple && option.pressed" :icon="checkmark" aria-hidden="true" />
      {{ option.label }}
    </ion-chip>
  </div>
</template>

<script setup lang="ts">
import { IonChip, IonIcon } from '@ionic/vue';
import { checkmark } from 'ionicons/icons';

export interface FilterOption {
  value: string;
  label: string;
  pressed: boolean;
}

/**
 * One line of the filter panel above an idea table (spec §11 décision 72):
 * `multiple` chips add up (État, Source), the others pick one (Occasion).
 */
withDefaults(defineProps<{ label: string; groupLabel: string; options: FilterOption[]; multiple?: boolean }>(), { multiple: false });
const emit = defineEmits<{ toggle: [value: string] }>();
</script>

<style scoped>
.filter-group {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}

.filter-group__label {
  width: 84px;
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--ic-text-secondary);
}

.filter-group ion-icon {
  margin: 0 4px 0 0;
  font-size: 1rem;
  color: inherit;
}
</style>
