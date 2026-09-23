<template>
  <ion-page>
    <ion-content>
      <ion-refresher slot="fixed" @ion-refresh="onRefresh">
        <ion-refresher-content />
      </ion-refresher>

      <ScreenHeader>
        <template #kicker>
          {{ acting ? t('acting.listOf', { name: acting.displayName }) : t('myList.hello', { name: auth.user?.displayName ?? '' }) }}
        </template>
        {{ acting ? acting.displayName : t('myList.title') }}
        <template #end>
          <ion-button class="ic-round-button" :aria-label="t('myList.search')" @click="toggleSearch">
            <ion-icon slot="icon-only" :icon="searchOutline" />
          </ion-button>
        </template>
      </ScreenHeader>

      <ion-searchbar
        v-if="searchOpen"
        v-model="search"
        class="search"
        :placeholder="t('myList.searchPlaceholder')"
        :debounce="300"
        @ion-input="reload"
      />

      <ion-segment v-model="tab" class="ic-segment" mode="ios" @ion-change="reload">
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
          <ion-icon slot="icon-only" :icon="closeOutline" />
        </ion-button>
      </div>

      <OccasionChips v-model="occasion" class="chips" @update:model-value="reload" />

      <div class="list-bar">
        <span class="ic-muted">{{ t('ideas.count', { count: totalItems }, totalItems) }}</span>
        <ion-button fill="clear" color="dark" class="sort" @click="chooseSort">
          <ion-icon slot="start" :icon="swapVerticalOutline" />
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
          <IdeaCard v-for="idea in suggestions" :key="idea.id" :idea="idea">
            <template #pills>
              <StatusPill variant="suggestion">{{ t('ideas.suggestedBy', { name: idea.author?.displayName }) }}</StatusPill>
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
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
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
  type RefresherCustomEvent,
} from '@ionic/vue';
import { useAuthStore } from '../stores/auth';
import { useActiveProfileStore } from '../stores/activeProfile';
import InteractionPills from '../components/InteractionPills.vue';
import SecretBand from '../components/SecretBand.vue';
import SectionTitle from '../components/SectionTitle.vue';
import StatusPill from '../components/StatusPill.vue';
import { ideasApi } from '../services/ideas';
import { useIdeaActions } from '../composables/useIdeaActions';
import EmptyState from '../components/EmptyState.vue';
import IdeaCard from '../components/IdeaCard.vue';
import OccasionChips from '../components/OccasionChips.vue';
import ScreenHeader from '../components/ScreenHeader.vue';
import type { Idea, IdeaListQuery, IdeaSort } from '../types/idea';

const BANNER_KEY = 'ic.surpriseBannerDismissed';

const { t } = useI18n();
const auth = useAuthStore();
const activeProfile = useActiveProfileStore();
/** The child whose list this is, when managing one (X-Acting-As). */
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

async function loadMore(event: InfiniteScrollCustomEvent): Promise<void> {
  page.value += 1;
  await fetchPage();
  await event.target.complete();
}

async function onRefresh(event: RefresherCustomEvent): Promise<void> {
  await reload();
  event.target.complete();
}

onIonViewWillEnter(() => {
  reload();
});

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
  font-size: 22px;
}

.surprise-banner p {
  flex-grow: 1;
  margin: 0;
  font-size: 14px;
  line-height: 1.4;
}

.surprise-banner__close {
  --color: var(--ic-primary-text-on-soft);
  width: 44px;
  height: 44px;
  margin: -10px -10px 0 0;
}

.chips {
  margin-top: 14px;
}

.list-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin: 6px 0;
  font-size: 14px;
}

.sort {
  margin: 0;
  font-size: 14px;
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
