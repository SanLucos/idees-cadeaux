<template>
  <ion-page>
    <ion-content>
      <TopBar default-href="/tabs/list">{{ t('privateIdeas.title') }}</TopBar>
      <p class="ic-muted intro">{{ t('privateIdeas.intro') }}</p>

      <section v-for="group in groups" :key="group.recipientId">
        <SectionTitle :icon="lockClosedOutline" variant="private">
          {{ group.isMe ? t('privateIdeas.forMe') : t('privateIdeas.forFriend', { name: group.name }) }}
        </SectionTitle>
        <div class="ic-stack">
          <IdeaCard v-for="idea in group.ideas" :key="idea.id" :idea="idea" show-private-note>
            <template #action>
              <ion-button class="ic-button-surface publish" size="small" @click="actions.publish(idea)">
                {{ t('ideas.actions.publish') }}
              </ion-button>
            </template>
          </IdeaCard>
        </div>
      </section>

      <EmptyState v-if="loaded && !groups.length" :icon="lockClosedOutline">{{ t('privateIdeas.empty') }}</EmptyState>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { lockClosedOutline } from 'ionicons/icons';
import { IonButton, IonContent, IonPage, onIonViewWillEnter } from '@ionic/vue';
import { useLocalRefresh } from '../composables/useLocalRefresh';
import { ideasApi } from '../services/ideas';
import { useAuthStore } from '../stores/auth';
import { useIdeaActions } from '../composables/useIdeaActions';
import EmptyState from '../components/EmptyState.vue';
import IdeaCard from '../components/IdeaCard.vue';
import SectionTitle from '../components/SectionTitle.vue';
import TopBar from '../components/TopBar.vue';
import type { PrivateIdea } from '../types/idea';
import { joinNames } from '../utils/names';
import { recipientsKey, recipientsOf } from '../utils/recipients';

const { t, locale } = useI18n();
const auth = useAuthStore();

const ideas = ref<PrivateIdea[]>([]);
const loaded = ref(false);

/**
 * Spec §5.4: "tous mes brouillons, groupés par destinataire" — mine
 * first. A draft for several people (décision 69) has its own group.
 */
const groups = computed(() => {
  const me = auth.user?.id;
  const byRecipients = new Map<string, { recipientId: string; name: string | null; isMe: boolean; ideas: PrivateIdea[] }>();
  for (const idea of ideas.value) {
    const people = recipientsOf(idea);
    const key = recipientsKey(people.map((r) => r.id));
    if (!byRecipients.has(key)) {
      const isMe = 1 === people.length && people[0].id === me;
      const name = joinNames(people.map((r) => (r.id === me ? t('privateIdeas.meInList') : r.displayName)), locale.value);
      byRecipients.set(key, { recipientId: key, name, isMe, ideas: [] });
    }
    byRecipients.get(key)!.ideas.push(idea);
  }

  return [...byRecipients.values()].sort((a, b) => Number(b.isMe) - Number(a.isMe) || (a.name ?? '').localeCompare(b.name ?? '', locale.value));
});

const actions = useIdeaActions(() => load());

async function load(): Promise<void> {
  ideas.value = await ideasApi.privateIdeas();
  loaded.value = true;
}

onIonViewWillEnter(() => {
  load();
});
useLocalRefresh(load);
</script>

<style scoped>
.intro {
  margin: 0;
}

.publish {
  margin: 0 6px 0 0;
  flex-shrink: 0;
}
</style>
