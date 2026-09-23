<template>
  <div class="idea-card" :class="{ 'idea-card--draft': isDraft }">
    <router-link :to="`/ideas/${idea.id}`" class="idea-card__main">
      <IdeaThumb :id="idea.id" :url="idea.thumbnailUrl" />
      <div class="idea-card__text">
        <div class="idea-card__title">{{ idea.title }}</div>
        <div class="idea-card__meta">
          <span v-if="price" class="idea-card__price">{{ price }}</span>
          <span v-if="price && occasionLabel" aria-hidden="true">·</span>
          <span v-if="occasionLabel">{{ occasionLabel }}</span>
          <template v-if="isDraft && showPrivateNote">
            <span v-if="price || occasionLabel" aria-hidden="true">·</span>
            <span>{{ t('ideas.visibleToYouOnly') }}</span>
          </template>
        </div>
        <div v-if="$slots.pills" class="idea-card__pills"><slot name="pills" /></div>
      </div>
    </router-link>
    <slot name="action">
      <ion-button
        v-if="actions"
        fill="clear"
        color="medium"
        class="idea-card__more"
        :aria-label="t('common.actionsFor', { name: idea.title })"
        @click="emit('actions', idea)"
      >
        <ion-icon slot="icon-only" :icon="ellipsisHorizontal" />
      </ion-button>
    </slot>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { IonButton, IonIcon } from '@ionic/vue';
import { ellipsisHorizontal } from 'ionicons/icons';
import IdeaThumb from './IdeaThumb.vue';
import { formatPrice } from '../utils/price';
import type { Idea } from '../types/idea';

/**
 * DESIGN.md § 6 IdeaCard. It shows only what the idea payload carries:
 * the owner view has nothing hidden to show, so no variant can leak
 * one (DESIGN.md § 3.2).
 */
const props = withDefaults(defineProps<{ idea: Idea; actions?: boolean; showPrivateNote?: boolean }>(), {
  actions: false,
  showPrivateNote: false,
});
const emit = defineEmits<{ actions: [idea: Idea] }>();

const { t, te, locale } = useI18n();

const price = computed(() => formatPrice(props.idea.priceAmount, props.idea.priceCurrency, locale.value));
const occasionLabel = computed(() => {
  const code = props.idea.occasion;
  if (!code) return null;

  return te(`occasions.${code}`) ? t(`occasions.${code}`) : code;
});
const isDraft = computed(() => 'private' === props.idea.visibility);
</script>

<style scoped>
.idea-card {
  display: flex;
  gap: 4px;
  align-items: center;
  padding: 12px 6px 12px 12px;
  border-radius: var(--ic-radius-card);
  background: var(--ic-surface);
  border: 1px solid var(--ic-border);
}

.idea-card--draft {
  border-style: dashed;
  background: transparent;
}

.idea-card__main {
  flex-grow: 1;
  display: flex;
  gap: 12px;
  align-items: center;
  min-width: 0;
  color: inherit;
  text-decoration: none;
}

.idea-card__text {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}

.idea-card__title {
  font-size: 16px;
  font-weight: 600;
  line-height: 1.3;
}

.idea-card__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  font-size: 14px;
  color: var(--ic-text-secondary);
}

.idea-card__price {
  font-weight: 600;
  color: var(--ion-text-color);
}

.idea-card__pills {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 2px;
}

.idea-card__more {
  flex-shrink: 0;
  width: 44px;
  height: 44px;
  margin: 0;
}
</style>
