<template>
  <ion-page>
    <ion-content>
      <ion-refresher slot="fixed" @ion-refresh="onRefresh">
        <ion-refresher-content />
      </ion-refresher>

      <TopBar default-href="/tabs/friends">
        <template #end>
          <ion-button class="ic-round-button" :aria-label="t('common.moreActions')" @click="openMenu">
            <ion-icon slot="icon-only" :icon="ellipsisHorizontal" />
          </ion-button>
        </template>
      </TopBar>

      <template v-if="profile">
        <div class="identity">
          <AppAvatar :id="profile.id" :name="profile.displayName" :url="profile.avatarUrl" :size="72" />
          <div>
            <h1 class="identity__name">{{ profile.displayName }}</h1>
            <div v-if="birthday" class="identity__birthday">
              <ion-icon :icon="calendarOutline" aria-hidden="true" />
              {{ birthday }}
            </div>
          </div>
        </div>

        <div class="cta">
          <ion-button class="ic-button-surface" expand="block" :router-link="`/tabs/friends/${userId}/profile`">
            <ion-icon slot="start" :icon="shirtOutline" />
            {{ t('friendList.sizesAndTastes') }}
          </ion-button>
          <ion-button expand="block" :router-link="{ path: '/ideas/new', query: { ownerId: userId } }">
            <ion-icon slot="start" :icon="add" />
            {{ t('friendList.suggest') }}
          </ion-button>
        </div>

        <SecretBand class="band">{{ t('friendList.secretBand', { name: profile.displayName }) }}</SecretBand>

        <OccasionChips v-model="occasion" class="chips" @update:model-value="loadAll" />

        <SectionTitle>{{ t('friendList.theirIdeas', { count: personal.totalItems }) }}</SectionTitle>
        <div class="ic-stack">
          <IdeaCard v-for="idea in personal.items" :key="idea.id" :idea="idea">
            <template #pills><InteractionPills :idea="idea" /></template>
          </IdeaCard>
        </div>
        <EmptyState v-if="loaded && !personal.items.length">{{ t('friendList.empty.personal', { name: profile.displayName }) }}</EmptyState>
        <ion-button v-if="personal.items.length < personal.totalItems" fill="clear" expand="block" @click="more(personal)">
          {{ t('common.showMore') }}
        </ion-button>

        <template v-if="suggestions.items.length">
          <SectionTitle :icon="eyeOffOutline" variant="secret">{{ t('friendList.suggestions', { count: suggestions.totalItems }) }}</SectionTitle>
          <div class="ic-stack">
            <IdeaCard v-for="idea in suggestions.items" :key="idea.id" :idea="idea" :actions="idea.canEdit" @actions="actions.openSheet">
              <template #pills>
                <StatusPill variant="suggestion">
                  {{ idea.isMine ? t('ideas.suggestedByMe') : t('ideas.suggestedBy', { name: idea.author?.displayName }) }}
                </StatusPill>
                <InteractionPills :idea="idea" />
              </template>
            </IdeaCard>
          </div>
          <ion-button v-if="suggestions.items.length < suggestions.totalItems" fill="clear" expand="block" @click="more(suggestions)">
            {{ t('common.showMore') }}
          </ion-button>
        </template>

        <template v-if="drafts.items.length">
          <SectionTitle :icon="lockClosedOutline" variant="private">{{ t('friendList.myDrafts', { name: profile.displayName }) }}</SectionTitle>
          <div class="ic-stack">
            <IdeaCard v-for="idea in drafts.items" :key="idea.id" :idea="idea" show-private-note>
              <template #action>
                <ion-button class="ic-button-surface publish" size="small" @click="actions.publish(idea)">
                  {{ t('ideas.actions.publish') }}
                </ion-button>
              </template>
            </IdeaCard>
          </div>
        </template>

        <router-link v-if="archivedCount" :to="`/tabs/friends/${userId}/archives`" class="ic-card archives-link">
          <ion-icon :icon="archiveOutline" aria-hidden="true" />
          <span class="archives-link__label">{{ t('friendList.archives') }}</span>
          <span class="ic-muted">{{ archivedCount }}</span>
          <ion-icon :icon="chevronForward" aria-hidden="true" />
        </router-link>
        <div class="bottom-space" />
      </template>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import {
  add,
  archiveOutline,
  calendarOutline,
  chevronForward,
  ellipsisHorizontal,
  eyeOffOutline,
  lockClosedOutline,
  shirtOutline,
} from 'ionicons/icons';
import {
  actionSheetController,
  alertController,
  IonButton,
  IonContent,
  IonIcon,
  IonPage,
  IonRefresher,
  IonRefresherContent,
  onIonViewWillEnter,
  useIonRouter,
  type RefresherCustomEvent,
} from '@ionic/vue';
import { api } from '../services/api';
import { ideasApi } from '../services/ideas';
import { useFriendsStore } from '../stores/friends';
import { useIdeaActions } from '../composables/useIdeaActions';
import { daysUntilBirthday, formatBirthday } from '../utils/birthday';
import AppAvatar from '../components/AppAvatar.vue';
import EmptyState from '../components/EmptyState.vue';
import IdeaCard from '../components/IdeaCard.vue';
import InteractionPills from '../components/InteractionPills.vue';
import OccasionChips from '../components/OccasionChips.vue';
import SecretBand from '../components/SecretBand.vue';
import SectionTitle from '../components/SectionTitle.vue';
import StatusPill from '../components/StatusPill.vue';
import TopBar from '../components/TopBar.vue';
import type { Idea, IdeaListQuery } from '../types/idea';

interface FriendProfile {
  id: string;
  displayName: string | null;
  avatarUrl: string | null;
  birthDay: number | null;
  birthMonth: number | null;
}

interface Section {
  query: IdeaListQuery;
  items: Idea[];
  totalItems: number;
  page: number;
}

const { t, locale } = useI18n();
const route = useRoute();
const ionRouter = useIonRouter();
const friendsStore = useFriendsStore();

const userId = String(route.params.id);
const profile = ref<FriendProfile | null>(null);
const occasion = ref<string | null>(null);
const loaded = ref(false);
const archivedCount = ref(0);

const section = (query: IdeaListQuery): Section => reactive({ query, items: [], totalItems: 0, page: 1 });
// Server-side filtering decides what each section may contain (spec §4):
// the client only asks for the slices it lays out.
const personal = section({ kind: 'personal', visibility: 'published' });
const suggestions = section({ kind: 'suggestion', visibility: 'published' });
const drafts = section({ visibility: 'private' });

const actions = useIdeaActions(() => loadAll());

const birthday = computed(() => {
  const p = profile.value;
  if (!p?.birthDay || !p.birthMonth) return null;
  const days = daysUntilBirthday(p.birthDay, p.birthMonth);
  const date = formatBirthday(p.birthDay, p.birthMonth, locale.value);

  return `${date} · ${0 === days ? t('friendList.birthdayToday') : t('friendList.birthdayIn', { days }, days)}`;
});

async function fetchSection(s: Section): Promise<void> {
  const result = await ideasApi.list({ ...s.query, occasion: occasion.value, page: s.page }, userId);
  s.items = 1 === s.page ? result.member : [...s.items, ...result.member];
  s.totalItems = result.totalItems;
}

async function loadAll(): Promise<void> {
  for (const s of [personal, suggestions, drafts]) s.page = 1;
  const [, , , archived] = await Promise.all([
    fetchSection(personal),
    fetchSection(suggestions),
    fetchSection(drafts),
    ideasApi.list({ status: 'archived', itemsPerPage: 1 }, userId),
  ]);
  archivedCount.value = archived.totalItems;
  loaded.value = true;
}

async function more(s: Section): Promise<void> {
  s.page += 1;
  await fetchSection(s);
}

async function load(): Promise<void> {
  profile.value = await api.get<FriendProfile>(`/users/${userId}`);
  await loadAll();
}

async function onRefresh(event: RefresherCustomEvent): Promise<void> {
  await load();
  event.target.complete();
}

onIonViewWillEnter(() => {
  load();
});

async function openMenu(): Promise<void> {
  const sheet = await actionSheetController.create({
    buttons: [
      { text: t('friends.remove'), role: 'destructive', handler: () => void confirmRemove() },
      { text: t('common.cancel'), role: 'cancel' },
    ],
  });
  await sheet.present();
}

async function confirmRemove(): Promise<void> {
  const alert = await alertController.create({
    header: t('friends.removeConfirmTitle'),
    message: t('friends.removeConfirmMessage'),
    buttons: [
      { text: t('common.cancel'), role: 'cancel' },
      {
        text: t('friends.remove'),
        role: 'destructive',
        handler: async () => {
          if (!friendsStore.friends.length) await friendsStore.fetchFriends();
          const friendship = friendsStore.friends.find((f) => f.user.id === userId);
          if (friendship) await friendsStore.remove(friendship.id);
          ionRouter.navigate('/tabs/friends', 'back', 'replace');
        },
      },
    ],
  });
  await alert.present();
}
</script>

<style scoped>
.identity {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-top: 8px;
}

.identity__name {
  margin: 0;
  font-size: 32px;
  line-height: 1.1;
}

.identity__birthday {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 6px;
  color: var(--ic-text-secondary);
  font-size: 15px;
}

.cta {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  margin-top: 20px;
}

.cta ion-button {
  margin: 0;
}

.band {
  margin-top: 16px;
}

.chips {
  margin-top: 16px;
}

.publish {
  margin: 0 6px 0 0;
  flex-shrink: 0;
}

.archives-link {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-top: 20px;
  padding: 16px;
  color: var(--ion-text-color);
  text-decoration: none;
  font-size: 16px;
}

.archives-link__label {
  flex-grow: 1;
}

.bottom-space {
  height: 24px;
}
</style>
