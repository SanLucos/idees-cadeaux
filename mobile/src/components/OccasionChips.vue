<template>
  <div class="ic-chip-row" role="group" :aria-label="t('ideas.filterByOccasion')">
    <ion-chip v-press
      class="ic-chip"
      :class="{ 'ic-chip-selected': null === modelValue }"
      :aria-pressed="null === modelValue"
      @click="emit('update:modelValue', null)"
    >
      {{ allLabel ?? t('ideas.allOccasions') }}
    </ion-chip>
    <ion-chip v-press
      v-for="o in occasions"
      :key="o.code"
      class="ic-chip"
      :class="{ 'ic-chip-selected': o.code === modelValue }"
      :aria-pressed="o.code === modelValue"
      @click="emit('update:modelValue', o.code)"
    >
      {{ t(`occasions.${o.code}`) }}
    </ion-chip>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { IonChip } from '@ionic/vue';
import { useOccasionsStore } from '../stores/occasions';

const props = defineProps<{ modelValue: string | null; allLabel?: string; limit?: number }>();
const emit = defineEmits<{ 'update:modelValue': [code: string | null] }>();

const { t } = useI18n();
const store = useOccasionsStore();

const occasions = computed(() => (props.limit ? store.occasions.slice(0, props.limit) : store.occasions));

onMounted(() => store.ensureLoaded());
</script>
