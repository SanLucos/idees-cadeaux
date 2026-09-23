<template>
  <ion-page>
    <ion-content>
      <ion-refresher slot="fixed" @ion-refresh="pullToRefresh">
        <ion-refresher-content />
      </ion-refresher>

      <ScreenHeader>
        {{ t('activity.title') }}
        <template #end>
          <ion-button v-if="store.unreadCount" fill="clear" class="read-all" @click="markAllRead">{{ t('activity.markAllRead') }}</ion-button>
          <ion-button class="ic-round-button" :aria-label="t('activity.settings')" router-link="/settings/notifications">
            <ion-icon slot="icon-only" :icon="optionsOutline" />
          </ion-button>
        </template>
      </ScreenHeader>

      <EmptyState v-if="loaded && !items.length" :icon="notificationsOutline">{{ t('activity.empty') }}</EmptyState>

      <section v-for="group in groups" :key="group.key">
        <SectionTitle>{{ t(`activity.groups.${group.key}`) }}</SectionTitle>
        <div class="ic-card group">
          <button
            v-for="item in group.items"
            :key="item.id"
            type="button"
            class="item"
            :class="{ 'item--unread': !item.readAt }"
            @click="open(item)"
          >
            <AppAvatar :id="person(item)?.id ?? item.id" :name="person(item)?.displayName" :size="44" />
            <div class="item__body">
              <StatusPill v-if="item.payload.subject" variant="success" :icon="happyOutline" class="item__for">
                {{ t('activity.for', { name: item.payload.subject.displayName }) }}
              </StatusPill>
              <i18n-t :keypath="textKey(item)" tag="p" class="item__text" scope="global" :plural="item.payload.days ?? 0">
                <template #actor><strong>{{ item.payload.actor?.displayName }}</strong></template>
                <template #friend><strong>{{ item.payload.friend?.displayName }}</strong></template>
                <template #idea>{{ item.payload.idea?.title }}</template>
                <template #owner>{{ item.payload.owner?.displayName }}</template>
                <template #manager>{{ item.payload.actor?.managedBy }}</template>
                <template #days>{{ item.payload.days }}</template>
              </i18n-t>
              <span class="item__time">{{ when(item.createdAt) }}</span>
            </div>
            <span v-if="!item.readAt" class="item__dot" :aria-label="t('activity.unread')" />
          </button>
        </div>
      </section>

      <ion-infinite-scroll :disabled="items.length >= total" @ion-infinite="loadMore">
        <ion-infinite-scroll-content />
      </ion-infinite-scroll>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { happyOutline, notificationsOutline, optionsOutline } from 'ionicons/icons';
import {
  IonButton,
  IonContent,
  IonIcon,
  IonInfiniteScroll,
  IonInfiniteScrollContent,
  IonPage,
  IonRefresher,
  IonRefresherContent,
  onIonViewWillEnter,
  useIonRouter,
  type InfiniteScrollCustomEvent,
} from '@ionic/vue';
import { useLocalRefresh } from '../composables/useLocalRefresh';
import { notificationsApi } from '../services/notifications';
import { useNotificationsStore } from '../stores/notifications';
import { useActiveProfileStore } from '../stores/activeProfile';
import { notificationTarget } from '../utils/notificationTarget';
import { relativeTime } from '../utils/relativeTime';
import AppAvatar from '../components/AppAvatar.vue';
import EmptyState from '../components/EmptyState.vue';
import ScreenHeader from '../components/ScreenHeader.vue';
import SectionTitle from '../components/SectionTitle.vue';
import StatusPill from '../components/StatusPill.vue';
import type { AppNotification } from '../types/notification';

const { t, locale } = useI18n();
const ionRouter = useIonRouter();
const store = useNotificationsStore();
const activeProfile = useActiveProfileStore();

const items = ref<AppNotification[]>([]);
const total = ref(0);
const page = ref(1);
const loaded = ref(false);

/** Notifications.png: Aujourd'hui / Cette semaine / Plus tôt. */
const groups = computed(() => {
  const startOfToday = new Date();
  startOfToday.setHours(0, 0, 0, 0);
  const weekAgo = new Date(startOfToday.getTime() - 6 * 86_400_000);
  const buckets: Record<string, AppNotification[]> = { today: [], week: [], earlier: [] };
  for (const item of items.value) {
    const at = new Date(item.createdAt);
    buckets[at >= startOfToday ? 'today' : at >= weekAgo ? 'week' : 'earlier'].push(item);
  }

  return Object.entries(buckets)
    .filter(([, list]) => list.length)
    .map(([key, list]) => ({ key, items: list }));
});

async function fetchPage(): Promise<void> {
  const result = await notificationsApi.list(page.value);
  items.value = 1 === page.value ? result.member : [...items.value, ...result.member];
  total.value = result.totalItems;
  loaded.value = true;
}

async function reload(): Promise<void> {
  page.value = 1;
  await fetchPage();
}

onIonViewWillEnter(() => {
  reload();
});
const { pullToRefresh } = useLocalRefresh(reload);

async function loadMore(event: InfiniteScrollCustomEvent): Promise<void> {
  page.value += 1;
  await fetchPage();
  await event.target.complete();
}


async function markAllRead(): Promise<void> {
  await notificationsApi.markAllRead();
  items.value = items.value.map((i) => ({ ...i, readAt: i.readAt ?? new Date().toISOString() }));
}

async function open(item: AppNotification): Promise<void> {
  if (!item.readAt) {
    item.readAt = new Date().toISOString();
    void notificationsApi.markRead(item.id);
  }
  const target = notificationTarget(item);
  if (target.actAs) activeProfile.switchTo(target.actAs);
  ionRouter.push(target.path);
}

function person(item: AppNotification) {
  return item.payload.actor ?? item.payload.friend ?? item.payload.owner;
}

function textKey(item: AppNotification): string {
  // « Jules (profil géré par Luc) vous a envoyé une demande d'ami ».
  const managed = 'friend_request_received' === item.type && item.payload.actor?.managedBy;

  return `activity.items.${item.type}${managed ? '_managed' : ''}`;
}

function when(iso: string): string {
  const at = new Date(iso);
  const startOfToday = new Date();
  startOfToday.setHours(0, 0, 0, 0);
  if (at >= startOfToday) return relativeTime(iso, locale.value) ?? t('interactions.justNow');
  if (at.getTime() >= startOfToday.getTime() - 6 * 86_400_000) {
    return new Intl.DateTimeFormat(locale.value, { weekday: 'long' }).format(at);
  }

  return new Intl.DateTimeFormat(locale.value, { day: 'numeric', month: 'long' }).format(at);
}
</script>

<style scoped>
.read-all {
  margin: 0;
  font-weight: 700;
  --color: var(--ion-color-primary);
}

.group {
  overflow: hidden;
}

.item {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  width: 100%;
  padding: 14px 16px;
  border: 0;
  border-bottom: 1px solid var(--ic-border);
  background: var(--ic-surface);
  color: var(--ion-text-color);
  font: inherit;
  text-align: left;
}

.item:last-child {
  border-bottom: 0;
}

.item--unread {
  background: color-mix(in srgb, var(--ic-primary-soft) 30%, var(--ic-surface));
}

.item__body {
  flex-grow: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 4px;
}

.item__for {
  margin-bottom: 2px;
}

.item__text {
  margin: 0;
  font-size: 16px;
  line-height: 1.4;
}

.item__time {
  font-size: 14px;
  color: var(--ic-text-secondary);
}

.item__dot {
  flex-shrink: 0;
  width: 10px;
  height: 10px;
  margin-top: 8px;
  border-radius: 50%;
  background: var(--ion-color-primary);
}
</style>
