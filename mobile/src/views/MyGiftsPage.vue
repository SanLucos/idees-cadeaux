<template>
  <ion-page>
    <ion-content>
      <ion-refresher slot="fixed" @ion-refresh="pullToRefresh">
        <ion-refresher-content />
      </ion-refresher>

      <TopBar default-href="/tabs/friends">{{ t('myGifts.title') }}</TopBar>

      <SecretBand class="band">{{ t('myGifts.secretBand') }}</SecretBand>

      <div class="ic-chip-row" role="group" :aria-label="t('myGifts.filterByKind')">
        <ion-chip
          v-for="k in kinds"
          :key="k"
          v-press
          class="ic-chip"
          :class="{ 'ic-chip-selected': kind === k }"
          :aria-pressed="kind === k"
          @click="kind = k"
        >
          {{ t(`myGifts.kinds.${k}`, { count: countOf(k) }) }}
        </ion-chip>
      </div>

      <div v-if="people.length > 1" class="ic-chip-row people" role="group" :aria-label="t('myGifts.filterByFriend')">
        <ion-chip v-press class="ic-chip" :class="{ 'ic-chip-selected': null === person }" :aria-pressed="null === person" @click="person = null">
          {{ t('myGifts.allFriends') }}
        </ion-chip>
        <ion-chip
          v-for="p in people"
          :key="p.id"
          v-press
          class="ic-chip"
          :class="{ 'ic-chip-selected': p.id === person }"
          :aria-pressed="p.id === person"
          @click="person = p.id"
        >
          {{ p.displayName }}
        </ion-chip>
      </div>

      <div v-if="!sync.lastSyncedAt && !all.length" class="loading"><ion-spinner name="dots" /></div>
      <EmptyState v-else-if="!groups.length" :icon="giftOutline">
        {{ all.length ? t('myGifts.empty.filtered') : t('myGifts.empty.none') }}
      </EmptyState>

      <section v-for="g in groups" :key="g.recipient.id" class="group">
        <router-link :to="recipientLink(g.recipient.id)" class="group__head">
          <AppAvatar :id="g.recipient.id" :name="g.recipient.displayName" :url="g.recipient.avatarUrl" :size="40" />
          <h2 class="group__name">{{ g.recipient.displayName }}</h2>
          <span v-if="null !== g.days" class="birthday-pill">
            <ion-icon :icon="calendarOutline" aria-hidden="true" />{{ t('friends.daysBefore', { days: g.days }) }}
          </span>
        </router-link>
        <div class="ic-stack">
          <IdeaCard v-for="gift in g.gifts" :key="gift.idea.id" :idea="gift.idea">
            <template #pills>
              <StatusPill v-if="'reserved' === gift.kind" variant="success" :icon="checkmark">{{ t('interactions.youGiveIt') }}</StatusPill>
              <StatusPill v-else-if="'contribution' === gift.kind" variant="secret" :icon="cashOutline">{{ contributionLabel(gift) }}</StatusPill>
              <template v-else>
                <StatusPill variant="neutral" :icon="giftOutline">
                  {{ 'received' === gift.idea.archiveKind ? t('myGifts.received') : t('myGifts.given') }}
                </StatusPill>
                <StatusPill v-if="'contribution' === gift.via" variant="secret" :icon="cashOutline">{{ t('myGifts.viaContribution') }}</StatusPill>
              </template>
              <span v-if="myPledge(gift)" class="ic-muted pledge">{{ t('myGifts.myPledge', { amount: myPledge(gift) }) }}</span>
            </template>
          </IdeaCard>
        </div>
      </section>
      <div class="bottom-space" />
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { calendarOutline, cashOutline, checkmark, giftOutline } from 'ionicons/icons';
import { IonChip, IonContent, IonIcon, IonPage, IonRefresher, IonRefresherContent, IonSpinner, onIonViewWillEnter } from '@ionic/vue';
import { useLocalRefresh } from '../composables/useLocalRefresh';
import { useLocalDb } from '../offline/db';
import { repo } from '../offline/repo';
import { useSync } from '../offline/sync';
import AppAvatar from '../components/AppAvatar.vue';
import EmptyState from '../components/EmptyState.vue';
import IdeaCard from '../components/IdeaCard.vue';
import SecretBand from '../components/SecretBand.vue';
import StatusPill from '../components/StatusPill.vue';
import TopBar from '../components/TopBar.vue';
import { groupMyGifts, type MyGiftFilter } from '../utils/myGifts';
import { formatPrice } from '../utils/price';
import type { MyGift } from '../types/idea';

/**
 * « Mes cadeaux pour mes amis » (spec §11 décision 62): read from the
 * device's synced friend views, so it works offline. Only my own
 * reservations and contributions are listed, never anyone else's.
 */
const { t, locale } = useI18n();
const db = useLocalDb();
const sync = useSync();

const kinds: MyGiftFilter[] = ['all', 'reserved', 'contribution', 'given'];
const kind = ref<MyGiftFilter>('all');
const person = ref<string | null>(null);

// Re-read whenever the device copy moves (a sync, a queued write).
const all = computed(() => (db.revision, repo.myGifts()));
const people = computed(() => {
  const seen = new Map<string, MyGift['recipient']>();
  for (const g of all.value) seen.set(g.recipient.id, g.recipient);

  return [...seen.values()].sort((a, b) => (a.displayName ?? '').localeCompare(b.displayName ?? '', locale.value));
});
const groups = computed(() => groupMyGifts(all.value, { kind: kind.value, person: person.value }));

const { pullToRefresh } = useLocalRefresh(() => undefined);
// Outside the tabs, which run the sync loop: opened directly (a reload on
// the web, where the device copy lives in memory), it must sync itself.
onIonViewWillEnter(() => void sync.run());

function countOf(k: MyGiftFilter): number {
  return all.value.filter((g) => ('all' === k || g.kind === k) && (null === person.value || g.recipient.id === person.value)).length;
}

function recipientLink(id: string): string {
  return db.get('managed_profile', id) ? `/profile/children/${id}` : `/tabs/friends/${id}`;
}

function contributionLabel(gift: MyGift): string {
  const c = gift.idea.contribution!;
  const total = formatPrice(c.totalAmount, c.currency, locale.value);
  const target = formatPrice(c.targetAmount, c.currency, locale.value);
  const label = target ? t('interactions.contributionProgress', { total, target }) : t('interactions.contributionTotal', { total });

  return 'closed' === c.status ? `${label} · ${t('interactions.contributionClosed')}` : label;
}

/** My own amount only (CLAUDE.md règle 3): `myPledge` is never anyone else's. */
function myPledge(gift: MyGift): string | null {
  const c = gift.idea.contribution;

  return c?.myPledge ? formatPrice(c.myPledge, c.currency, locale.value) : null;
}
</script>

<style scoped>
.band {
  margin-bottom: 14px;
}

.people {
  margin-top: 8px;
}

.group {
  margin-top: 22px;
}

.group__head {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 10px;
  color: inherit;
  text-decoration: none;
}

.group__name {
  flex-grow: 1;
  margin: 0;
  font-family: var(--ic-font-body);
  font-size: 1rem;
  font-weight: 700;
}

.birthday-pill {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 4px 10px;
  border-radius: 999px;
  background: var(--ic-primary-soft);
  color: var(--ic-primary-text-on-soft);
  font-size: 0.8125rem;
  font-weight: 700;
}

.pledge {
  display: inline-flex;
  align-items: center;
  min-height: 28px;
  font-size: 0.875rem;
}

.loading {
  display: flex;
  justify-content: center;
  padding: 32px;
}

.bottom-space {
  height: 24px;
}
</style>
