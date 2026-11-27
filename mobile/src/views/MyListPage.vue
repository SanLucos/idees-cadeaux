<template>
  <ion-page class="ic-wide">
    <ion-content>
      <ion-refresher slot="fixed" @ion-refresh="pullToRefresh">
        <ion-refresher-content />
      </ion-refresher>

      <ScreenHeader>
        <template #kicker>
          {{ acting ? t('acting.listOf', { name: acting.displayName }) : t('myList.hello', { name: auth.user?.displayName ?? '' }) }}
        </template>
        {{ acting ? acting.displayName : t('myList.title') }}
        <template #end>
          <SyncBadge />
          <ion-searchbar
            v-if="desktop"
            v-model="search"
            class="search search--desktop"
            :aria-label="t('myList.search')"
            :placeholder="t('myList.search')"
            :debounce="200"
          />
          <ion-button v-else class="ic-round-button" :aria-label="t('myList.search')" @click="toggleSearch">
            <ion-icon aria-hidden="true" slot="icon-only" :icon="searchOutline" />
          </ion-button>
        </template>
      </ScreenHeader>

      <ion-searchbar
        v-if="searchOpen && !desktop"
        v-model="search"
        class="search"
        :placeholder="t('myList.searchPlaceholder')"
        :debounce="300"
        @ion-input="onSearchInput"
      />

      <ion-segment v-if="!desktop" v-model="tab" class="ic-segment" mode="ios" scrollable @ion-change="reload">
        <ion-segment-button value="published">
          <ion-label>{{ t('myList.tabs.published', { count: counts.published }) }}</ion-label>
        </ion-segment-button>
        <ion-segment-button value="drafts">
          <ion-label>{{ t('myList.tabs.drafts', { count: counts.drafts }) }}</ion-label>
        </ion-segment-button>
        <ion-segment-button value="archived">
          <ion-label>{{ t('myList.tabs.archived') }}</ion-label>
        </ion-segment-button>
      </ion-segment>

      <!-- Vue gestionnaire (spec §5.15): what the child's friends do shows here, in plum. -->
      <SecretBand v-if="acting" class="manager-band">{{ t('acting.managerBand', { name: acting.displayName }) }}</SecretBand>

      <div v-if="showSurpriseBanner && !acting" class="surprise-banner" role="note">
        <ion-icon :icon="giftOutline" aria-hidden="true" />
        <p><strong>{{ t('myList.surprise.title') }}</strong> {{ t('myList.surprise.body') }}</p>
        <ion-button fill="clear" class="surprise-banner__close" :aria-label="t('myList.surprise.dismiss')" @click="dismissBanner">
          <ion-icon aria-hidden="true" slot="icon-only" :icon="closeOutline" />
        </ion-button>
      </div>

      <!-- Only my own reservations and contributions on friends' lists: nothing about this list (spec §4). -->
      <MyGiftsLink v-if="!acting" class="my-gifts" />

      <!-- Computer layout (spec §11 décision 72): every idea in one table, filtered on the device. -->
      <template v-if="desktop">
        <div class="ic-card filters">
          <FilterChipGroup multiple :label="t('desktop.filters.state')" :group-label="t('desktop.filters.byState')" :options="stateOptions" @toggle="toggleState" />
          <FilterChipGroup :label="t('desktop.filters.occasion')" :group-label="t('ideas.filterByOccasion')" :options="occasionFilter.options.value" @toggle="occasionFilter.pick" />
        </div>

        <div class="table-bar">
          <span class="ic-muted">
            {{ t('ideas.count', { count: rows.length }, rows.length) }}<template v-if="hiddenArchived">
              · {{ t('desktop.hiddenArchived', { count: hiddenArchived }, hiddenArchived) }}</template>
          </span>
          <router-link v-if="states.has('draft') && !acting" to="/tabs/list/private" class="all-drafts">
            <ion-icon :icon="lockClosedOutline" aria-hidden="true" />{{ t('myList.allDrafts') }}
          </router-link>
        </div>

        <IdeaTable
          v-if="rows.length"
          v-model:sort="tableSort"
          :ideas="rows"
          :columns="tableColumns"
          :owner-name="acting?.displayName ?? null"
          :actions="(idea) => idea.canEdit || idea.canUnarchive"
          @actions="actions.openSheet"
          @publish="actions.publish"
        />
        <EmptyState v-else-if="loaded" :icon="giftOutline">
          {{ t(allIdeas.length ? 'myList.empty.filtered' : 'myList.empty.published') }}
          <template v-if="!allIdeas.length" #action>
            <ion-button router-link="/ideas/new">{{ t('ideas.new') }}</ion-button>
          </template>
        </EmptyState>
      </template>

      <template v-else>
      <OccasionChips v-model="occasion" class="chips" @update:model-value="reload" />

      <div class="list-bar">
        <span class="ic-muted">{{ t('ideas.count', { count: totalItems }, totalItems) }}</span>
        <ion-button fill="clear" color="dark" class="sort" @click="chooseSort">
          <ion-icon aria-hidden="true" slot="start" :icon="swapVerticalOutline" />
          {{ t(`ideas.sort.${sort}`) }}
        </ion-button>
      </div>

      <router-link v-if="'drafts' === tab" to="/tabs/list/private" class="ic-card private-link">
        <ion-icon :icon="lockClosedOutline" aria-hidden="true" />
        <span>{{ t('myList.allDrafts') }}</span>
        <ion-icon :icon="chevronForward" aria-hidden="true" />
      </router-link>

      <div class="ic-stack">
        <IdeaCard v-for="idea in ideas" :key="idea.id" :idea="idea" actions @actions="actions.openSheet">
          <!-- Empty in the owner view: it carries no hidden field. -->
          <template #pills><InteractionPills :idea="idea" /></template>
        </IdeaCard>
      </div>

      <template v-if="acting && 'published' === tab && suggestions.length">
        <SectionTitle :icon="eyeOffOutline" variant="secret">{{ t('friendList.suggestions', { count: suggestions.length }) }}</SectionTitle>
        <div class="ic-stack">
          <IdeaCard v-for="idea in suggestions" :key="idea.id" :idea="idea" :actions="idea.canEdit" @actions="actions.openSheet">
            <template #pills>
              <StatusPill variant="suggestion">
                {{ idea.isMine ? t('ideas.suggestedByMe') : t('ideas.suggestedBy', { name: idea.author?.displayName }) }}
              </StatusPill>
              <InteractionPills :idea="idea" />
            </template>
          </IdeaCard>
        </div>
      </template>

      <EmptyState v-if="loaded && !ideas.length" :icon="giftOutline">
        {{ t(filtered ? 'myList.empty.filtered' : `myList.empty.${tab}`) }}
        <template v-if="'published' === tab && !filtered" #action>
          <ion-button router-link="/ideas/new">{{ t('ideas.new') }}</ion-button>
        </template>
      </EmptyState>

      <ion-infinite-scroll :disabled="ideas.length >= totalItems" @ion-infinite="loadMore">
        <ion-infinite-scroll-content />
      </ion-infinite-scroll>
      </template>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { chevronForward, closeOutline, eyeOffOutline, giftOutline, lockClosedOutline, searchOutline, swapVerticalOutline } from 'ionicons/icons';
import {
  actionSheetController,
  IonButton,
  IonContent,
  IonIcon,
  IonInfiniteScroll,
  IonInfiniteScrollContent,
  IonLabel,
  IonPage,
  IonRefresher,
  IonRefresherContent,
  IonSearchbar,
  IonSegment,
  IonSegmentButton,
  onIonViewWillEnter,
  type InfiniteScrollCustomEvent,
} from '@ionic/vue';
import { useLocalRefresh } from '../composables/useLocalRefresh';
import { useAuthStore } from '../stores/auth';
import { useActiveProfileStore } from '../stores/activeProfile';
import InteractionPills from '../components/InteractionPills.vue';
import MyGiftsLink from '../components/MyGiftsLink.vue';
import SecretBand from '../components/SecretBand.vue';
import SectionTitle from '../components/SectionTitle.vue';
import StatusPill from '../components/StatusPill.vue';
import { ideasApi } from '../services/ideas';
import { useIdeaActions } from '../composables/useIdeaActions';
import EmptyState from '../components/EmptyState.vue';
import IdeaCard from '../components/IdeaCard.vue';
import OccasionChips from '../components/OccasionChips.vue';
import ScreenHeader from '../components/ScreenHeader.vue';
import SyncBadge from '../components/SyncBadge.vue';
import FilterChipGroup, { type FilterOption } from '../components/FilterChipGroup.vue';
import IdeaTable, { type IdeaColumn } from '../components/IdeaTable.vue';
import { useDesktop } from '../composables/useDesktop';
import { useIdeaLabels } from '../composables/useIdeaLabels';
import { useOccasionFilter } from '../composables/useOccasionFilter';
import {
  countBy,
  DEFAULT_SORT,
  filterIdeas,
  ideaState,
  readStoredSet,
  sortIdeas,
  storeSet,
  toggled,
  uniqueIdeas,
  type IdeaState,
  type TableSort,
} from '../utils/ideaTable';
import type { Idea, IdeaListQuery, IdeaSort } from '../types/idea';

const BANNER_KEY = 'ic.surpriseBannerDismissed';
/** The « État » filter of the computer layout, kept between visits. */
const STATES_KEY = 'ic.myList.states';
const ALL_STATES: readonly IdeaState[] = ['published', 'draft', 'archived'];

const { t, locale } = useI18n();
const route = useRoute();
const router = useRouter();
const activeProfile = useActiveProfileStore();
/** The child whose list this is, when managing one (X-Acting-As). */
const auth = useAuthStore();
const acting = computed(() => activeProfile.active);
const suggestions = ref<Idea[]>([]);

const tab = ref<'published' | 'drafts' | 'archived'>('published');
const occasion = ref<string | null>(null);
const sort = ref<IdeaSort>('recent');
const search = ref('');
const searchOpen = ref(false);
const ideas = ref<Idea[]>([]);
const totalItems = ref(0);
const page = ref(1);
const loaded = ref(false);
const counts = ref({ published: 0, drafts: 0, archived: 0 });
const showSurpriseBanner = ref(readBannerVisible());

const filtered = computed(() => null !== occasion.value || '' !== search.value.trim());

// ---- Computer layout (spec §11 décision 72) ----
const desktop = useDesktop();
const labels = useIdeaLabels();
/** Archived ideas pile up over the years: hidden until asked for. */
const states = ref<Set<IdeaState>>(readStoredSet(STATES_KEY, ALL_STATES, ['published', 'draft']));
const tableSort = ref<TableSort>(DEFAULT_SORT);
const allIdeas = ref<Idea[]>([]);

const stateCounts = computed(() => countBy(allIdeas.value, ideaState));
const byState = computed(() => filterIdeas(allIdeas.value, { states: states.value, search: search.value }));
const rows = computed(() =>
  sortIdeas(
    filterIdeas(byState.value, { occasion: occasion.value }),
    tableSort.value,
    { occasion: labels.occasionLabel, source: (idea) => labels.sourceLabel(idea, acting.value?.displayName ?? null) },
    locale.value,
  ),
);
const hiddenArchived = computed(() => (states.value.has('archived') ? 0 : (stateCounts.value.archived ?? 0)));
const tableColumns = computed<IdeaColumn[]>(() =>
  // Managing a child: friends' suggestions and what they do show too, in plum (spec §5.15).
  acting.value ? ['idea', 'state', 'source', 'occasion', 'price', 'status', 'interactions', 'added'] : ['idea', 'state', 'occasion', 'price', 'added'],
);

const stateOptions = computed<FilterOption[]>(() =>
  (['published', 'draft', 'archived'] as const).map((state) => ({
    value: state,
    label: t(`desktop.states.${'draft' === state ? 'drafts' : state}`, { count: stateCounts.value[state] ?? 0 }),
    pressed: states.value.has(state),
  })),
);

const occasionFilter = useOccasionFilter(() => byState.value, occasion);

function toggleState(value: string): void {
  states.value = toggled(states.value, value as IdeaState);
  storeSet(STATES_KEY, states.value);
}


async function loadTable(): Promise<void> {
  const kind = acting.value ? 'personal' : undefined;
  const slices = await Promise.all([
    ideasApi.listAll({ status: 'active', visibility: 'published', kind }),
    ideasApi.listAll({ status: 'active', visibility: 'private', kind }),
    ideasApi.listAll({ status: 'archived', kind }),
    acting.value ? ideasApi.listAll({ kind: 'suggestion', visibility: 'published' }) : Promise.resolve([]),
  ]);
  allIdeas.value = uniqueIdeas(slices.flat());
  loaded.value = true;
}

function onSearchInput(): void {
  // On a computer the table filters itself.
  if (!desktop.value) void reload();
}

// Any change can move the idea to another segment or change the counts: reload.
const actions = useIdeaActions(() => reload());

function query(): IdeaListQuery {
  return {
    status: 'archived' === tab.value ? 'archived' : 'active',
    visibility: 'archived' === tab.value ? undefined : 'drafts' === tab.value ? 'private' : 'published',
    // Managing a child: its own ideas here, friends' suggestions in their own section.
    kind: acting.value ? 'personal' : undefined,
    occasion: occasion.value,
    q: search.value.trim(),
    sort: sort.value,
    page: page.value,
  };
}

async function fetchPage(): Promise<void> {
  const result = await ideasApi.list(query());
  ideas.value = 1 === page.value ? result.member : [...ideas.value, ...result.member];
  totalItems.value = result.totalItems;
  if (result.counts) counts.value = result.counts;
  loaded.value = true;
}

async function reload(): Promise<void> {
  if (desktop.value) {
    await loadTable();

    return;
  }
  page.value = 1;
  await Promise.all([fetchPage(), fetchSuggestions()]);
}

async function fetchSuggestions(): Promise<void> {
  if (!acting.value) {
    suggestions.value = [];

    return;
  }
  const result = await ideasApi.list({ kind: 'suggestion', visibility: 'published', occasion: occasion.value, q: search.value.trim(), sort: sort.value, itemsPerPage: 50 });
  suggestions.value = result.member;
}

watch(() => activeProfile.activeId, () => reload());
watch(desktop, () => reload());

async function loadMore(event: InfiniteScrollCustomEvent): Promise<void> {
  page.value += 1;
  await fetchPage();
  await event.target.complete();
}


onIonViewWillEnter(() => {
  showRequestedTab();
  reload();
});

/** Issue #7: a new idea lands here, on its tab and unfiltered, so it shows. */
function showRequestedTab(): void {
  const requested = route.query.tab;
  if ('published' !== requested && 'drafts' !== requested) return;
  tab.value = requested;
  states.value = new Set([...states.value, 'drafts' === requested ? 'draft' : 'published']);
  occasion.value = null;
  search.value = '';
  searchOpen.value = false;
  // One-shot: the tab bar must not bring it back later.
  void router.replace({ path: route.path, query: {} });
}
const { pullToRefresh } = useLocalRefresh(reload);

function toggleSearch(): void {
  searchOpen.value = !searchOpen.value;
  if (!searchOpen.value && search.value) {
    search.value = '';
    reload();
  }
}

async function chooseSort(): Promise<void> {
  const options: IdeaSort[] = ['recent', 'price_asc', 'price_desc'];
  const sheet = await actionSheetController.create({
    header: t('ideas.sort.title'),
    buttons: [
      ...options.map((option) => ({
        text: t(`ideas.sort.${option}`),
        handler: () => {
          sort.value = option;
          reload();
        },
      })),
      { text: t('common.cancel'), role: 'cancel' },
    ],
  });
  await sheet.present();
}

/** A viewer convenience only: losing it just shows the tip again. */
function readBannerVisible(): boolean {
  try {
    return '1' !== localStorage.getItem(BANNER_KEY);
  } catch {
    return true;
  }
}

function dismissBanner(): void {
  showSurpriseBanner.value = false;
  try {
    localStorage.setItem(BANNER_KEY, '1');
  } catch {
    // Ignored: see readBannerVisible().
  }
}
</script>

<style scoped>
.search--desktop {
  width: 300px;
  padding: 0;
}

.filters {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-top: 16px;
  padding: 14px 16px;
}

.table-bar {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  margin: 16px 0 10px;
  font-size: 0.875rem;
}

.all-drafts {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 44px;
  font-weight: 600;
}

.search {
  padding: 0 0 8px;
  --background: var(--ic-surface);
  --border-radius: var(--ic-radius-field);
}

.manager-band {
  margin-top: 14px;
}

.surprise-banner {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  margin-top: 14px;
  padding: 14px;
  border-radius: var(--ic-radius-card);
  background: var(--ic-primary-soft);
  color: var(--ic-primary-text-on-soft);
}

.surprise-banner > ion-icon {
  flex-shrink: 0;
  font-size: 1.375rem;
}

.surprise-banner p {
  flex-grow: 1;
  margin: 0;
  font-size: 0.875rem;
  line-height: 1.4;
}

.surprise-banner__close {
  --color: var(--ic-primary-text-on-soft);
  width: 44px;
  height: 44px;
  margin: -10px -10px 0 0;
}

.my-gifts {
  margin-top: 14px;
}

.chips {
  margin-top: 14px;
}

.list-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin: 6px 0;
  font-size: 0.875rem;
}

.sort {
  margin: 0;
  font-size: 0.875rem;
}

.private-link {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 10px;
  padding: 14px;
  color: var(--ion-text-color);
  text-decoration: none;
  font-weight: 600;
}

.private-link span {
  flex-grow: 1;
}
</style>
