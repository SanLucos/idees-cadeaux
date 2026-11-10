<template>
  <ion-page>
    <ion-content>
      <ion-refresher slot="fixed" @ion-refresh="refresh">
        <ion-refresher-content />
      </ion-refresher>

      <ScreenHeader>
        <template #kicker><ProfileGreeting /></template>
        {{ t('friends.title') }}
        <template #end>
          <ion-button class="ic-round-button" :aria-label="t('friends.add_screen.title')" router-link="/tabs/friends/add">
            <ion-icon aria-hidden="true" slot="icon-only" :icon="personAddOutline" />
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
        <ion-button v-if="!web" class="quick-action" :router-link="{ name: 'AddFriend', query: { contacts: '1' } }">
          <div>
            <ion-icon :icon="bookOutline" color="primary" aria-hidden="true" />
            <span>{{ t('friends.byContacts') }}</span>
          </div>
        </ion-button>
        <ion-button class="quick-action" router-link="/profile/share">
          <div>
            <ion-icon :icon="linkOutline" color="primary" aria-hidden="true" />
            <span>{{ t('shareLink.myLink') }}</span>
          </div>
        </ion-button>
      </div>

      <!-- My own gifts, not the child's: hidden while acting as one. -->
      <MyGiftsLink v-if="!activeProfile.active" class="my-gifts" />

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
                <div v-if="f.suggestedBy" class="ic-muted">{{ t('friends.suggestedBy', { names: f.suggestedBy.displayName }) }}</div>
              </div>
            </div>
            <div class="request-card__actions">
              <ion-button class="ic-button-surface" expand="block" @click="store.decline(f.id)">{{ t('friends.decline') }}</ion-button>
              <ion-button color="dark" expand="block" @click="store.accept(f.id)">{{ t('friends.accept') }}</ion-button>
            </div>
          </div>
        </div>
      </template>

      <!-- Spec §11 décision 65: my own suggestions, not the child's. -->
      <template v-if="!activeProfile.active && suggestions.received.length">
        <SectionTitle>{{ t('friends.suggestions.title', { count: suggestions.received.length }) }}</SectionTitle>
        <div class="ic-stack">
          <div v-for="s in suggestions.received" :key="s.id" class="ic-card request-card" data-cy="friend-suggestion">
            <div class="request-card__who">
              <AppAvatar :id="s.suggested.id" :name="s.suggested.displayName" :url="s.suggested.avatarUrl" :size="56" />
              <div>
                <div class="name">{{ s.suggested.displayName }}</div>
                <div class="ic-muted">{{ t('friends.suggestions.from', { names: suggesters(s) }, s.suggestedBy.length) }}</div>
              </div>
            </div>
            <div class="request-card__actions">
              <ion-button class="ic-button-surface" expand="block" :disabled="busy" @click="answer(() => suggestions.dismiss(s.id))">
                {{ t('friends.suggestions.dismiss') }}
              </ion-button>
              <ion-button color="dark" expand="block" :disabled="busy" @click="answer(() => suggestions.sendRequest(s.id), t('friends.suggestions.sent'))">
                {{ t('friends.suggestions.sendRequest') }}
              </ion-button>
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
import { onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { bookOutline, calendarOutline, linkOutline, mailOutline, peopleOutline, personAddOutline } from 'ionicons/icons';
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
  toastController,
  type RefresherCustomEvent,
} from '@ionic/vue';
import { useFriendSuggestionsStore } from '../stores/friendSuggestions';
import { useErrorMessage } from '../composables/useErrorMessage';
import { joinNames } from '../utils/names';
import type { ReceivedFriendSuggestion } from '../types/friendSuggestion';
import { useFriendsStore } from '../stores/friends';
import { isWeb } from '../utils/platform';
import { useActiveProfileStore } from '../stores/activeProfile';
import { useLocalRefresh } from '../composables/useLocalRefresh';
import AppAvatar from '../components/AppAvatar.vue';
import EmptyState from '../components/EmptyState.vue';
import MyGiftsLink from '../components/MyGiftsLink.vue';
import ProfileGreeting from '../components/ProfileGreeting.vue';
import ScreenHeader from '../components/ScreenHeader.vue';
import SectionTitle from '../components/SectionTitle.vue';
import { daysUntilBirthday, formatBirthday, UPCOMING_BIRTHDAY_DAYS } from '../utils/birthday';
import type { Friendship } from '../types/friendship';

const { t, locale } = useI18n();
const store = useFriendsStore();
const web = isWeb();
const activeProfile = useActiveProfileStore();

const suggestions = useFriendSuggestionsStore();
const { describe } = useErrorMessage();
const busy = ref(false);

/** Online only (spec §11 décision 35): offline, the section simply isn't there. */
function fetchSuggestions(): void {
  if (activeProfile.active) return;
  suggestions.fetchReceived().catch(() => undefined);
}

// Switching profile shows the other profile's friends.
watch(() => activeProfile.activeId, () => {
  store.fetchAll();
  fetchSuggestions();
});

onMounted(() => {
  store.fetchAll();
  fetchSuggestions();
});

const { pullToRefresh: localRefresh } = useLocalRefresh(() => store.refresh());

function refresh(event: RefresherCustomEvent): Promise<void> {
  fetchSuggestions();

  return localRefresh(event);
}

function suggesters(s: ReceivedFriendSuggestion): string {
  return joinNames(s.suggestedBy.map((p) => p.displayName), locale.value);
}

async function answer(action: () => Promise<void>, done?: string): Promise<void> {
  busy.value = true;
  try {
    await action();
    if (done) await (await toastController.create({ message: done, duration: 2500, color: 'success' })).present();
  } catch (e) {
    await (await toastController.create({ message: describe(e), duration: 3000, color: 'danger' })).present();
  } finally {
    busy.value = false;
  }
}

function upcoming(f: Friendship): number | null {
  const { birthDay, birthMonth } = f.user;
  if (!birthDay || !birthMonth) return null;
  const days = daysUntilBirthday(birthDay, birthMonth);

  return days <= UPCOMING_BIRTHDAY_DAYS ? days : null;
}

function friendSubtitle(f: Friendship): string {
  const parts: string[] = [];
  if ('link' === f.origin) parts.push(t('friends.addedViaLink'));
  // Spec §11 décision 59: a child shared by its managers, or (acting as the child) one of their friends.
  if ('family' === f.origin && f.user.managedBy) parts.push(t('friends.childOf', { name: f.user.managedBy.displayName }));
  else if (f.user.managedBy) parts.push(t('children.managedBy', { name: f.user.managedBy.displayName }));
  if ('family' === f.origin && !f.user.managedBy) parts.push(t('friends.viaFriends'));
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
  /* Three across, fewer with large text (spec §9). */
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 6.5rem), 1fr));
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
  --padding-top: 10px;
  --padding-bottom: 10px;
  min-height: 76px;
  height: auto;
  margin: 0;
  white-space: normal;
}

.quick-action div {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  font-size: 0.875rem;
  font-weight: 700;
}

.quick-action ion-icon {
  font-size: 1.375rem;
}

.my-gifts {
  margin-top: 14px;
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
  font-size: 1rem;
  font-weight: 700;
}

ion-item ion-label p {
  color: var(--ic-text-secondary);
  font-size: 0.875rem;
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
</style>
