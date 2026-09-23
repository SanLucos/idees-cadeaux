<template>
  <ion-page>
    <ion-content>
      <TopBar :default-href="backHref">
        <template v-if="idea && hasActions" #end>
          <ion-button class="ic-round-button" :aria-label="t('common.actionsFor', { name: idea.title })" @click="actions.openSheet(idea)">
            <ion-icon slot="icon-only" :icon="ellipsisHorizontal" />
          </ion-button>
        </template>
      </TopBar>

      <EmptyState v-if="notFound" :icon="helpCircleOutline">{{ t('ideas.notFound') }}</EmptyState>

      <article v-if="idea">
        <div class="hero" :style="heroStyle">
          <img v-if="idea.imageUrl" :src="idea.imageUrl" alt="" />
          <ion-icon v-else :icon="imageOutline" aria-hidden="true" />
        </div>

        <div class="pills">
          <StatusPill v-if="idea.occasion" :icon="calendarOutline">{{ t(`occasions.${idea.occasion}`) }}</StatusPill>
          <StatusPill v-if="'private' === idea.visibility" variant="draft" :icon="lockClosedOutline">{{ t('ideas.visibleToYouOnly') }}</StatusPill>
          <StatusPill v-if="'archived' === idea.status" variant="success">{{ t(`ideas.archived.${idea.archiveKind}`) }}</StatusPill>
          <!-- Friend view only: the owner view carries no author at all. -->
          <template v-if="'friend' === idea.view && idea.author">
            <StatusPill v-if="idea.isSuggestion" variant="suggestion">
              {{ idea.isMine ? t('ideas.suggestedByMe') : t('ideas.suggestedBy', { name: idea.author.displayName }) }}
            </StatusPill>
            <StatusPill v-else>{{ t('ideas.ideaOf', { name: idea.author.displayName }) }}</StatusPill>
          </template>
        </div>

        <h1 class="title">{{ idea.title }}</h1>
        <div v-if="price" class="price">{{ price }}</div>
        <p v-if="idea.note" class="note">{{ idea.note }}</p>

        <a v-if="idea.url" class="product-link" :href="idea.url" target="_blank" rel="noopener noreferrer">
          <ion-icon :icon="openOutline" aria-hidden="true" />
          {{ t('ideas.productLink') }}
        </a>

        <div class="buttons">
          <ion-button v-if="idea.canEdit" class="ic-button-surface" expand="block" :router-link="`/ideas/${idea.id}/edit`">
            <ion-icon slot="start" :icon="createOutline" />
            {{ t('ideas.actions.edit') }}
          </ion-button>
          <ion-button v-if="idea.canEdit && 'private' === idea.visibility" expand="block" @click="actions.publish(idea)">
            {{ t('ideas.actions.publish') }}
          </ion-button>
          <ion-button v-if="'owner' === idea.view && 'active' === idea.status" class="ic-button-surface" expand="block" @click="actions.archive(idea)">
            <ion-icon slot="start" :icon="checkmarkDoneOutline" />
            {{ t('ideas.actions.markReceived') }}
          </ion-button>
          <ion-button v-if="actions.canMarkGifted(idea)" class="ic-button-surface" expand="block" @click="actions.archive(idea)">
            <ion-icon slot="start" :icon="archiveOutline" />
            {{ t('ideas.actions.markGifted') }}
          </ion-button>
          <ion-button v-if="idea.canUnarchive" class="ic-button-surface" expand="block" @click="actions.unarchive(idea)">
            {{ t('ideas.actions.unarchive') }}
          </ion-button>
        </div>
      </article>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import {
  archiveOutline,
  calendarOutline,
  checkmarkDoneOutline,
  createOutline,
  ellipsisHorizontal,
  helpCircleOutline,
  imageOutline,
  lockClosedOutline,
  openOutline,
} from 'ionicons/icons';
import { IonButton, IonContent, IonIcon, IonPage, onIonViewWillEnter, useIonRouter } from '@ionic/vue';
import { ApiError } from '../services/api';
import { ideasApi } from '../services/ideas';
import { useIdeaActions } from '../composables/useIdeaActions';
import { colorIndex } from '../utils/colorIndex';
import { formatPrice } from '../utils/price';
import EmptyState from '../components/EmptyState.vue';
import StatusPill from '../components/StatusPill.vue';
import TopBar from '../components/TopBar.vue';
import type { Idea } from '../types/idea';

const { t, locale } = useI18n();
const route = useRoute();
const ionRouter = useIonRouter();

const id = String(route.params.id);
const idea = ref<Idea | null>(null);
const notFound = ref(false);

const actions = useIdeaActions((change) => {
  if ('deleted' === change.type) {
    ionRouter.navigate(backHref.value, 'back', 'pop');
  } else {
    idea.value = change.idea;
  }
});

const backHref = computed(() => (idea.value && 'friend' === idea.value.view ? `/tabs/friends/${idea.value.ownerId}` : '/tabs/list'));
const hasActions = computed(() => !!idea.value && (idea.value.canEdit || idea.value.canUnarchive || 'owner' === idea.value.view));
const price = computed(() => (idea.value ? formatPrice(idea.value.priceAmount, idea.value.priceCurrency, locale.value) : null));
const heroStyle = computed(() => {
  const index = colorIndex(id, 5);

  return { background: `var(--ic-thumb-bg-${index})`, color: `var(--ic-thumb-fg-${index})` };
});

onIonViewWillEnter(async () => {
  try {
    idea.value = await ideasApi.get(id);
  } catch (e) {
    // Hidden or gone: the API answers 404 either way, and so do we.
    if (e instanceof ApiError && 404 === e.status) notFound.value = true;
    else throw e;
  }
});
</script>

<style scoped>
.hero {
  display: flex;
  align-items: center;
  justify-content: center;
  aspect-ratio: 1 / 1;
  max-height: 360px;
  width: 100%;
  border-radius: var(--ic-radius-block);
  overflow: hidden;
  font-size: 48px;
}

.hero img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.pills {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 16px;
}

.title {
  margin: 14px 0 0;
  font-size: 28px;
  line-height: 1.2;
}

.price {
  margin-top: 8px;
  font-size: 22px;
  font-weight: 700;
}

.note {
  margin: 10px 0 0;
  font-size: 16px;
  line-height: 1.5;
  white-space: pre-line;
}

.product-link {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-height: 44px;
  margin-top: 8px;
  font-weight: 600;
  text-decoration: underline;
}

.buttons {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin: 20px 0 24px;
}

.buttons ion-button {
  margin: 0;
}
</style>
