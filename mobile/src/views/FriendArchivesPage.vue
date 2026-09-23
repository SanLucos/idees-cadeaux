<template>
  <ion-page>
    <ion-content>
      <TopBar :default-href="`/tabs/friends/${userId}`">{{ t('friendList.archives') }}</TopBar>

      <div class="ic-stack">
        <IdeaCard v-for="idea in ideas" :key="idea.id" :idea="idea" :actions="idea.canUnarchive || idea.canEdit" @actions="actions.openSheet">
          <template v-if="idea.isSuggestion" #pills>
            <StatusPill variant="suggestion">
              {{ idea.isMine ? t('ideas.suggestedByMe') : t('ideas.suggestedBy', { name: idea.author?.displayName }) }}
            </StatusPill>
          </template>
        </IdeaCard>
      </div>
      <EmptyState v-if="loaded && !ideas.length" :icon="archiveOutline">{{ t('friendList.empty.archives') }}</EmptyState>

      <ion-infinite-scroll :disabled="ideas.length >= totalItems" @ion-infinite="loadMore">
        <ion-infinite-scroll-content />
      </ion-infinite-scroll>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import { archiveOutline } from 'ionicons/icons';
import { IonContent, IonInfiniteScroll, IonInfiniteScrollContent, IonPage, onIonViewWillEnter, type InfiniteScrollCustomEvent } from '@ionic/vue';
import { ideasApi } from '../services/ideas';
import { useIdeaActions } from '../composables/useIdeaActions';
import EmptyState from '../components/EmptyState.vue';
import IdeaCard from '../components/IdeaCard.vue';
import StatusPill from '../components/StatusPill.vue';
import TopBar from '../components/TopBar.vue';
import type { Idea } from '../types/idea';

const { t } = useI18n();
const route = useRoute();
const userId = String(route.params.id);

const ideas = ref<Idea[]>([]);
const totalItems = ref(0);
const page = ref(1);
const loaded = ref(false);

const actions = useIdeaActions(() => reload());

async function fetchPage(): Promise<void> {
  const result = await ideasApi.list({ status: 'archived', page: page.value }, userId);
  ideas.value = 1 === page.value ? result.member : [...ideas.value, ...result.member];
  totalItems.value = result.totalItems;
  loaded.value = true;
}

async function reload(): Promise<void> {
  page.value = 1;
  await fetchPage();
}

async function loadMore(event: InfiniteScrollCustomEvent): Promise<void> {
  page.value += 1;
  await fetchPage();
  await event.target.complete();
}

onIonViewWillEnter(() => {
  reload();
});
</script>
