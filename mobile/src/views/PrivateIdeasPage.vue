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

const { t } = useI18n();
const auth = useAuthStore();

const ideas = ref<PrivateIdea[]>([]);
const loaded = ref(false);

/** Spec §5.4: "tous mes brouillons, groupés par destinataire" — mine first. */
const groups = computed(() => {
  const byRecipient = new Map<string, { recipientId: string; name: string | null; isMe: boolean; ideas: PrivateIdea[] }>();
  for (const idea of ideas.value) {
    const id = idea.recipient.id;
    if (!byRecipient.has(id)) {
      byRecipient.set(id, { recipientId: id, name: idea.recipient.displayName, isMe: id === auth.user?.id, ideas: [] });
    }
    byRecipient.get(id)!.ideas.push(idea);
  }

  return [...byRecipient.values()].sort((a, b) => Number(b.isMe) - Number(a.isMe) || (a.name ?? '').localeCompare(b.name ?? ''));
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
