<template>
  <router-link to="/gifts" class="ic-card my-gifts-link">
    <ion-icon :icon="giftOutline" color="secret" aria-hidden="true" />
    <span class="my-gifts-link__label">{{ t('myGifts.title') }}</span>
    <span v-if="count" class="ic-muted" :aria-label="t('myGifts.toOffer', { count }, count)">{{ count }}</span>
    <ion-icon :icon="chevronForward" aria-hidden="true" />
  </router-link>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { IonIcon } from '@ionic/vue';
import { chevronForward, giftOutline } from 'ionicons/icons';
import { useLocalDb } from '../offline/db';
import { repo } from '../offline/repo';
import { giftsToOffer } from '../utils/myGifts';

/** Entry to « Mes cadeaux pour mes amis » (Amis and Ma liste tabs), with the number still to offer. */
const { t } = useI18n();
const db = useLocalDb();
const count = computed(() => (db.revision, giftsToOffer(repo.myGifts())));
</script>

<style scoped>
.my-gifts-link {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px;
  color: var(--ion-text-color);
  text-decoration: none;
  font-weight: 600;
}

.my-gifts-link__label {
  flex-grow: 1;
}
</style>
