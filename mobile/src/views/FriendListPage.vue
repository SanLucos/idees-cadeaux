<template>
  <ion-page class="ic-wide">
    <ion-content>
      <ion-refresher slot="fixed" @ion-refresh="pullToRefresh">
        <ion-refresher-content />
      </ion-refresher>

      <TopBar :default-href="isMyChild ? `/profile/children/${userId}` : '/tabs/friends'">
        <template #end>
          <ion-button v-if="!isMyChild" class="ic-round-button" :aria-label="t('common.moreActions')" @click="openMenu">
            <ion-icon aria-hidden="true" slot="icon-only" :icon="ellipsisHorizontal" />
          </ion-button>
        </template>
      </TopBar>

      <template v-if="profile">
        <div class="head">
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
            <ion-icon aria-hidden="true" slot="start" :icon="shirtOutline" />
            {{ t('friendList.sizesAndTastes') }}
          </ion-button>
          <ion-button expand="block" :router-link="{ path: '/ideas/new', query: { ownerId: userId } }">
            <ion-icon aria-hidden="true" slot="start" :icon="add" />
            {{ t('friendList.suggest') }}
          </ion-button>
        </div>
        </div>

        <SecretBand class="band">{{ t('friendList.secretBand', { name: profile.displayName }) }}</SecretBand>

        <!-- Computer layout (spec §11 décision 72): ideas, suggestions, my drafts and archives in one table. -->
        <template v-if="desktop">
          <div class="ic-card filters">
            <FilterChipGroup multiple :label="t('desktop.filters.state')" :group-label="t('desktop.filters.byState')" :options="phaseOptions" @toggle="togglePhase" />
            <FilterChipGroup multiple :label="t('desktop.filters.source')" :group-label="t('desktop.filters.bySource')" :options="sourceOptions" @toggle="toggleSource" />
            <FilterChipGroup :label="t('desktop.filters.occasion')" :group-label="t('ideas.filterByOccasion')" :options="occasionFilter.options.value" @toggle="occasionFilter.pick" />
          </div>

          <SectionTitle>{{ t('ideas.count', { count: rows.length }, rows.length) }}</SectionTitle>
          <IdeaTable
            v-if="rows.length"
            v-model:sort="tableSort"
            :ideas="rows"
            :columns="['idea', 'source', 'occasion', 'price', 'status', 'interactions']"
            :owner-name="profile.displayName"
            :actions="(idea) => idea.canEdit || idea.canUnarchive || !!idea.canMarkGifted"
            @actions="actions.openSheet"
            @publish="actions.publish"
          />
          <EmptyState v-else-if="loaded">
            {{ allIdeas.length ? t('myList.empty.filtered') : t('friendList.empty.personal', { name: profile.displayName }) }}
          </EmptyState>
        </template>

        <template v-else>
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
        </template>

        <!-- After the ideas, which are what the page is for. -->
        <div class="introduce-section">
          <IntroduceFriend :user-id="userId" :name="profile.displayName" :child="!!profile.managedBy" />
        </div>
        <div class="bottom-space" />
      </template>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
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
} from '@ionic/vue';
import { useLocalRefresh } from '../composables/useLocalRefresh';
import { api } from '../services/api';
import { repo } from '../offline/repo';
import { ideasApi } from '../services/ideas';
import { useFriendsStore } from '../stores/friends';
import { useActiveProfileStore } from '../stores/activeProfile';
import { useIdeaActions } from '../composables/useIdeaActions';
import { daysUntilBirthday, formatBirthday } from '../utils/birthday';
import AppAvatar from '../components/AppAvatar.vue';
import EmptyState from '../components/EmptyState.vue';
import IdeaCard from '../components/IdeaCard.vue';
import InteractionPills from '../components/InteractionPills.vue';
import IntroduceFriend from '../components/IntroduceFriend.vue';
import OccasionChips from '../components/OccasionChips.vue';
import SecretBand from '../components/SecretBand.vue';
import SectionTitle from '../components/SectionTitle.vue';
import StatusPill from '../components/StatusPill.vue';
import TopBar from '../components/TopBar.vue';
import FilterChipGroup, { type FilterOption } from '../components/FilterChipGroup.vue';
import IdeaTable from '../components/IdeaTable.vue';
import { useDesktop } from '../composables/useDesktop';
import { useIdeaLabels } from '../composables/useIdeaLabels';
import { useOccasionFilter } from '../composables/useOccasionFilter';
import {
  countBy,
  DEFAULT_SORT,
  filterIdeas,
  ideaPhase,
  ideaSource,
  readStoredSet,
  sortIdeas,
  storeSet,
  toggled,
  uniqueIdeas,
  type IdeaPhase,
  type IdeaSource,
  type TableSort,
} from '../utils/ideaTable';
import type { Idea, IdeaListQuery } from '../types/idea';

interface FriendProfile {
  id: string;
  displayName: string | null;
  avatarUrl: string | null;
  birthDay: number | null;
  birthMonth: number | null;
  /** A child profile: its managers (spec §5.15). */
  managedBy?: { id: string } | null;
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
const activeProfile = useActiveProfileStore();

const userId = String(route.params.id);
/** One of my children, opened from its page (manager view, spec §11 décision 66): not a friend to remove. */
const isMyChild = computed(() => activeProfile.children.some((c) => c.id === userId));
const profile = ref<FriendProfile | null>(null);
const occasion = ref<string | null>(null);
const loaded = ref(false);
const archivedCount = ref(0);

const section = (query: IdeaListQuery): Section => reactive({ query, items: [], totalItems: 0, page: 1 });
// Server-side filtering decides what each section may contain (spec §4):
// the client only asks for the slices it lays out.
const personal = section({ kind: 'personal', visibility: 'published' });
const suggestions = section({ kind: 'suggestion', visibility: 'published' });
// My own drafts for them: never a managed child's drafts (GitHub #6).
const drafts = section({ kind: 'suggestion', visibility: 'private' });

const actions = useIdeaActions(() => loadAll());

// ---- Computer layout (spec §11 décision 72) ----
/** The « État » filter, kept between visits: archives hidden until asked for. */
const PHASES_KEY = 'ic.friendList.phases';
const desktop = useDesktop();
const labels = useIdeaLabels();
const phases = ref<Set<IdeaPhase>>(readStoredSet(PHASES_KEY, ['active', 'archived'], ['active']));
const sources = ref<Set<IdeaSource>>(new Set(['owner', 'suggestion', 'draft']));
const tableSort = ref<TableSort>(DEFAULT_SORT);
const allIdeas = ref<Idea[]>([]);

const phaseCounts = computed(() => countBy(allIdeas.value, ideaPhase));
const byPhase = computed(() => filterIdeas(allIdeas.value, { phases: phases.value }));
const sourceCounts = computed(() => countBy(byPhase.value, ideaSource));
const bySource = computed(() => filterIdeas(byPhase.value, { sources: sources.value }));
const rows = computed(() =>
  sortIdeas(
    filterIdeas(bySource.value, { occasion: occasion.value }),
    tableSort.value,
    { occasion: labels.occasionLabel, source: (idea) => labels.sourceLabel(idea, profile.value?.displayName ?? null) },
    locale.value,
  ),
);

const phaseOptions = computed<FilterOption[]>(() =>
  (['active', 'archived'] as const).map((phase) => ({
    value: phase,
    label: t(`desktop.states.${phase}`, { count: phaseCounts.value[phase] ?? 0 }),
    pressed: phases.value.has(phase),
  })),
);

const sourceOptions = computed<FilterOption[]>(() => {
  const labelOf = { owner: 'desktop.sources.owner', suggestion: 'desktop.sources.suggestions', draft: 'desktop.sources.drafts' } as const;

  // My drafts only show when I have some.
  return (['owner', 'suggestion', 'draft'] as const)
    .filter((source) => 'draft' !== source || sourceCounts.value.draft)
    .map((source) => ({
      value: source,
      label: t(labelOf[source], { name: profile.value?.displayName ?? '', count: sourceCounts.value[source] ?? 0 }),
      pressed: sources.value.has(source),
    }));
});

const occasionFilter = useOccasionFilter(() => bySource.value, occasion);

function togglePhase(value: string): void {
  phases.value = toggled(phases.value, value as IdeaPhase);
  storeSet(PHASES_KEY, phases.value);
}

function toggleSource(value: string): void {
  sources.value = toggled(sources.value, value as IdeaSource);
}


/** Same slices as the phone layout, archives included, all pages. */
async function loadTable(): Promise<void> {
  const slices = await Promise.all([
    ideasApi.listAll({ kind: 'personal', visibility: 'published' }, userId),
    ideasApi.listAll({ kind: 'suggestion', visibility: 'published' }, userId),
    ideasApi.listAll({ kind: 'suggestion', visibility: 'private' }, userId),
    ideasApi.listAll({ status: 'archived' }, userId),
  ]);
  allIdeas.value = uniqueIdeas(slices.flat());
  loaded.value = true;
}

watch(desktop, () => void loadAll());

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
  if (desktop.value) {
    await loadTable();

    return;
  }
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
  profile.value = (repo.user(userId) as FriendProfile | null) ?? (await api.get<FriendProfile>(`/users/${userId}`));
  await loadAll();
}


onIonViewWillEnter(() => {
  load();
});
const { pullToRefresh } = useLocalRefresh(loadAll);

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
.filters {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-top: 16px;
  padding: 14px 16px;
}

/* Computer layout: identity and actions on one line. */
:root[data-desktop] .head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 20px;
}

:root[data-desktop] .head .identity {
  flex-grow: 1;
  margin-top: 0;
}

:root[data-desktop] .head .cta {
  display: flex;
  flex-wrap: wrap;
  margin-top: 0;
}

.identity {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-top: 8px;
}

.identity__name {
  margin: 0;
  font-size: 2rem;
  line-height: 1.1;
}

.identity__birthday {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 6px;
  color: var(--ic-text-secondary);
  font-size: 0.9375rem;
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
  font-size: 1rem;
}

.archives-link__label {
  flex-grow: 1;
}

.introduce-section {
  margin-top: 24px;
}

.introduce-section:empty {
  display: none;
}

/* Computer: the button and its list keep a form's width (spec §11 décision 72). */
:root[data-desktop] .introduce-section {
  max-width: 640px;
}

.bottom-space {
  height: 24px;
}
</style>
