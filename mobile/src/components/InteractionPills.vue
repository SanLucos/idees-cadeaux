<template>
  <template v-if="idea.reservation">
    <StatusPill variant="success" :icon="checkmark">
      {{ idea.reservation.isMine ? t('interactions.reservedByMe') : t('interactions.reservedBy', { name: idea.reservation.user.displayName }) }}
    </StatusPill>
  </template>
  <StatusPill v-else-if="openContribution" variant="secret" :icon="cashOutline">
    {{ contributionLabel }}
  </StatusPill>
  <span v-if="idea.reactions?.count" class="counter" :aria-label="t('interactions.likes', { count: idea.reactions.count }, idea.reactions.count)">
    <ion-icon :icon="idea.reactions.likedByMe ? heart : heartOutline" aria-hidden="true" />{{ idea.reactions.count }}
  </span>
  <span v-if="idea.commentCount" class="counter" :aria-label="t('interactions.comments', { count: idea.commentCount }, idea.commentCount)">
    <ion-icon :icon="chatbubbleOutline" aria-hidden="true" />{{ idea.commentCount }}
  </span>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { IonIcon } from '@ionic/vue';
import { cashOutline, chatbubbleOutline, checkmark, heart, heartOutline } from 'ionicons/icons';
import StatusPill from './StatusPill.vue';
import { formatPrice } from '../utils/price';
import type { Idea } from '../types/idea';

/**
 * Status of an idea on a friend's list (ListeAmi mock-up): only built
 * from the hidden fields the API sent — absent in the owner view.
 */
const props = defineProps<{ idea: Idea }>();

const { t, locale } = useI18n();

const openContribution = computed(() => ('open' === props.idea.contribution?.status ? props.idea.contribution : null));
const contributionLabel = computed(() => {
  const c = openContribution.value!;
  const total = formatPrice(c.totalAmount, c.currency, locale.value);
  const target = formatPrice(c.targetAmount, c.currency, locale.value);

  return target ? t('interactions.contributionProgress', { total, target }) : t('interactions.contributionTotal', { total });
});
</script>

<style scoped>
.counter {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  min-height: 28px;
  font-size: 0.875rem;
  color: var(--ic-text-secondary);
}

.counter ion-icon {
  font-size: 1.0625rem;
}
</style>
