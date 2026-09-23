<template>
  <ion-page>
    <ion-content>
      <ion-refresher slot="fixed" @ion-refresh="refresh">
        <ion-refresher-content />
      </ion-refresher>

      <ScreenHeader>
        <template v-if="activeProfile.active" #kicker>{{ t('acting.friendsOf', { name: activeProfile.active.displayName }) }}</template>
        {{ t('friends.title') }}
        <template #end>
          <ion-button class="ic-round-button" :aria-label="t('friends.add_screen.title')" router-link="/tabs/friends/add">
            <ion-icon slot="icon-only" :icon="personAddOutline" />
          </ion-button>
        </template>
      </ScreenHeader>

      <div class="quick-actions">
        <ion-button class="quick-action" router-link="/tabs/friends/add">
          <div>
            <ion-icon :icon="mailOutline" color="primary" aria-hidden="true" />
            <span>{{ t('friends.byEmail') }}</span>
          </div>
        </ion-button>
        <ion-button class="quick-action" :router-link="{ name: 'AddFriend', query: { contacts: '1' } }">
          <div>
            <ion-icon :icon="bookOutline" color="primary" aria-hidden="true" />
            <span>{{ t('friends.byContacts') }}</span>
          </div>
        </ion-button>
      </div>

      <template v-if="store.incoming.length">
        <SectionTitle>{{ t('friends.incomingTitle', { count: store.incoming.length }) }}</SectionTitle>
        <div class="ic-stack">
          <div v-for="f in store.incoming" :key="f.id" class="ic-card request-card">
            <div class="request-card__who">
              <AppAvatar :id="f.user.id" :name="f.user.displayName" :url="f.user.avatarUrl" :size="56" />
              <div>
                <div class="name">{{ f.user.displayName }}</div>
                <div class="ic-muted">{{ t('friends.wantsToBeFriend') }}</div>
                <div v-if="f.user.managedBy" class="ic-muted managed">{{ t('children.managedBy', { name: f.user.managedBy.displayName }) }}</div>
              </div>
            </div>
            <div class="request-card__actions">
              <ion-button class="ic-button-surface" expand="block" @click="store.decline(f.id)">{{ t('friends.decline') }}</ion-button>
              <ion-button color="dark" expand="block" @click="store.accept(f.id)">{{ t('friends.accept') }}</ion-button>
            </div>
          </div>
        </div>
      </template>

      <SectionTitle>{{ t('friends.friendsTitle', { count: store.friends.length }) }}</SectionTitle>
      <EmptyState v-if="!store.friends.length" :icon="peopleOutline">{{ t('friends.empty.friends') }}</EmptyState>
      <ion-list v-else class="ic-card-list" lines="inset">
        <ion-item v-for="f in store.friends" :key="f.id" :router-link="`/tabs/friends/${f.user.id}`" button detail>
          <AppAvatar slot="start" :id="f.user.id" :name="f.user.displayName" :url="f.user.avatarUrl" :size="48" />
          <ion-label>
            <div class="name">{{ f.user.displayName }}</div>
            <p>{{ friendSubtitle(f) }}</p>
          </ion-label>
          <span v-if="upcoming(f) !== null" slot="end" class="birthday-pill">
            <ion-icon :icon="calendarOutline" aria-hidden="true" />{{ t('friends.daysBefore', { days: upcoming(f) }) }}
          </span>
        </ion-item>
      </ion-list>

      <template v-if="store.outgoing.length">
        <SectionTitle>{{ t('friends.outgoingTitle') }}</SectionTitle>
        <ion-list class="ic-card-list" lines="inset">
          <ion-item v-for="f in store.outgoing" :key="f.id">
            <AppAvatar slot="start" :id="f.user.id" :name="f.user.displayName" :url="f.user.avatarUrl" :size="40" />
            <ion-label>
              <div class="name">{{ f.user.displayName }}</div>
              <p>{{ t(`friends.status.${f.status}`) }}</p>
            </ion-label>
            <ion-button v-if="'pending' === f.status" slot="end" fill="clear" @click="store.cancel(f.id)">
              {{ t('friends.cancel') }}
            </ion-button>
          </ion-item>
        </ion-list>
      </template>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { onMounted, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { bookOutline, calendarOutline, mailOutline, peopleOutline, personAddOutline } from 'ionicons/icons';
import {
  IonButton,
  IonContent,
  IonIcon,
  IonItem,
  IonLabel,
  IonList,
  IonPage,
  IonRefresher,
  IonRefresherContent,
} from '@ionic/vue';
import { useFriendsStore } from '../stores/friends';
import { useActiveProfileStore } from '../stores/activeProfile';
import { useLocalRefresh } from '../composables/useLocalRefresh';
import AppAvatar from '../components/AppAvatar.vue';
import EmptyState from '../components/EmptyState.vue';
import ScreenHeader from '../components/ScreenHeader.vue';
import SectionTitle from '../components/SectionTitle.vue';
import { daysUntilBirthday, formatBirthday, UPCOMING_BIRTHDAY_DAYS } from '../utils/birthday';
import type { Friendship } from '../types/friendship';

const { t, locale } = useI18n();
const store = useFriendsStore();
const activeProfile = useActiveProfileStore();

// Switching profile shows the other profile's friends.
watch(() => activeProfile.activeId, () => store.fetchAll());

onMounted(() => {
  store.fetchAll();
});

const { pullToRefresh: refresh } = useLocalRefresh(() => store.refresh());

function upcoming(f: Friendship): number | null {
  const { birthDay, birthMonth } = f.user;
  if (!birthDay || !birthMonth) return null;
  const days = daysUntilBirthday(birthDay, birthMonth);

  return days <= UPCOMING_BIRTHDAY_DAYS ? days : null;
}

function friendSubtitle(f: Friendship): string {
  const parts: string[] = [];
  if ('link' === f.origin) parts.push(t('friends.addedViaLink'));
  if (f.user.managedBy) parts.push(t('children.managedBy', { name: f.user.managedBy.displayName }));
  if (typeof f.ideaCount === 'number') parts.push(t('friends.ideaCount', { count: f.ideaCount }, f.ideaCount));
  if (f.user.birthDay && f.user.birthMonth) {
    parts.push(t('friends.birthdayOn', { date: formatBirthday(f.user.birthDay, f.user.birthMonth, locale.value) }));
  }

  return parts.join(' · ');
}
</script>

<style scoped>
.quick-actions {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.quick-action {
  --background: var(--ic-surface);
  --background-hover: var(--ic-surface-muted);
  --color: var(--ion-text-color);
  --border-color: var(--ic-border);
  --border-style: solid;
  --border-width: 1px;
  --border-radius: var(--ic-radius-card);
  height: 76px;
  margin: 0;
}

.quick-action div {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  font-weight: 700;
}

.quick-action ion-icon {
  font-size: 22px;
}

.request-card {
  padding: 14px;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.request-card__who {
  display: flex;
  align-items: center;
  gap: 12px;
}

.request-card__actions {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}

.request-card__actions ion-button {
  margin: 0;
}

.name {
  font-size: 16px;
  font-weight: 700;
}

ion-item ion-label p {
  color: var(--ic-text-secondary);
  font-size: 14px;
}

.birthday-pill {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 4px 10px;
  border-radius: 999px;
  background: var(--ic-primary-soft);
  color: var(--ic-primary-text-on-soft);
  font-size: 13px;
  font-weight: 700;
}
</style>
