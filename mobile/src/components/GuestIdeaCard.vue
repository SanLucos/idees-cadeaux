<template>
  <article class="guest-card">
    <div class="guest-card__main">
      <IdeaThumb :id="idea.id" :url="idea.thumbnailUrl" />
      <div class="guest-card__text">
        <div class="guest-card__title">{{ idea.title }}</div>
        <div class="guest-card__meta">
          <span v-if="price" class="guest-card__price">{{ price }}</span>
          <span v-if="price && occasionLabel" aria-hidden="true">·</span>
          <span v-if="occasionLabel">{{ occasionLabel }}</span>
        </div>
        <p v-if="idea.note" class="guest-card__note">{{ idea.note }}</p>
        <a v-if="idea.url" class="guest-card__link" :href="idea.url" target="_blank" rel="noopener noreferrer">{{ t('shareLink.guest.seeLink') }}</a>
      </div>
    </div>
    <!-- Visible but inert (spec §5.16): each one leads to « Créer un compte pour interagir ». -->
    <div class="guest-card__actions">
      <ion-button class="ic-button-surface offer" expand="block" @click="emit('interact')">
        <ion-icon slot="start" :icon="giftOutline" aria-hidden="true" />{{ t('shareLink.guest.offer') }}
      </ion-button>
      <ion-button class="ic-button-surface square" :aria-label="t('shareLink.guest.like')" @click="emit('interact')">
        <ion-icon aria-hidden="true" slot="icon-only" :icon="heartOutline" />
      </ion-button>
      <ion-button class="ic-button-surface square" :aria-label="t('shareLink.guest.comment')" @click="emit('interact')">
        <ion-icon aria-hidden="true" slot="icon-only" :icon="chatboxOutline" />
      </ion-button>
    </div>
  </article>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { IonButton, IonIcon } from '@ionic/vue';
import { chatboxOutline, giftOutline, heartOutline } from 'ionicons/icons';
import IdeaThumb from './IdeaThumb.vue';
import { formatPrice } from '../utils/price';
import type { GuestIdea } from '../types/shareLink';

/** DESIGN.md § 6 IdeaCard, guest variant (VueInvite.png): the idea, no status, no counter. */
const props = defineProps<{ idea: GuestIdea }>();
const emit = defineEmits<{ interact: [] }>();

const { t, te, locale } = useI18n();

const price = computed(() => formatPrice(props.idea.priceAmount, props.idea.priceCurrency ?? 'EUR', locale.value));
const occasionLabel = computed(() => {
  const code = props.idea.occasion;
  if (!code) return null;

  return te(`occasions.${code}`) ? t(`occasions.${code}`) : code;
});
</script>

<style scoped>
.guest-card {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 12px;
  border-radius: var(--ic-radius-card);
  background: var(--ic-surface);
  border: 1px solid var(--ic-border);
}

.guest-card__main {
  display: flex;
  gap: 12px;
}

.guest-card__text {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}

.guest-card__title {
  font-size: 1rem;
  font-weight: 600;
  line-height: 1.3;
}

.guest-card__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  font-size: 0.875rem;
  color: var(--ic-text-secondary);
}

.guest-card__price {
  font-weight: 600;
  color: var(--ion-text-color);
}

.guest-card__note {
  margin: 2px 0 0;
  font-size: 0.875rem;
  color: var(--ic-text-secondary);
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
  white-space: pre-line;
}

.guest-card__link {
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--ion-color-primary);
}

.guest-card__actions {
  display: flex;
  gap: 8px;
}

.guest-card__actions ion-button {
  margin: 0;
  min-height: 44px;
  --border-radius: 12px;
}

.offer {
  flex-grow: 1;
}

.square {
  width: 44px;
  --padding-start: 0;
  --padding-end: 0;
}
</style>
