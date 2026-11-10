<template>
  <!-- Spec §11 décision 65: adults only, in my own name. -->
  <template v-if="canIntroduce">
    <ion-button expand="block" class="ic-button-surface introduce" :disabled="busy" data-cy="introduce" @click="pickRecipients">
      <ion-icon slot="start" :icon="peopleOutline" aria-hidden="true" />
      {{ t('friends.suggestions.introduce') }}
    </ion-button>
    <template v-if="introduced.length">
      <SectionTitle>{{ t('friends.suggestions.introducedTitle') }}</SectionTitle>
      <ion-list class="ic-card-list" lines="inset">
        <ion-item v-for="s in introduced" :key="s.id">
          <AppAvatar slot="start" :id="s.recipient.id" :name="s.recipient.displayName" :url="s.recipient.avatarUrl" :size="40" />
          <ion-label>
            <div class="name">{{ s.recipient.displayName }}</div>
            <p>{{ t('friends.suggestions.pending') }}</p>
          </ion-label>
          <ion-button slot="end" fill="clear" :disabled="busy" @click="run(() => suggestions.cancel(s.id))">
            {{ t('friends.suggestions.cancel') }}
          </ion-button>
        </ion-item>
      </ion-list>
    </template>
  </template>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { alertController, IonButton, IonIcon, IonItem, IonLabel, IonList, toastController } from '@ionic/vue';
import { peopleOutline } from 'ionicons/icons';
import AppAvatar from './AppAvatar.vue';
import SectionTitle from './SectionTitle.vue';
import { useErrorMessage } from '../composables/useErrorMessage';
import { useLocalRefresh } from '../composables/useLocalRefresh';
import { useActiveProfileStore } from '../stores/activeProfile';
import { useFriendsStore } from '../stores/friends';
import { useFriendSuggestionsStore } from '../stores/friendSuggestions';

/** « Présenter à des amis… » for friend B, with my pending introductions of B (spec §11 décision 65). */
const props = defineProps<{
  userId: string;
  name: string | null;
  /** B is a child profile: never introduced. */
  child: boolean;
}>();

const { t } = useI18n();
const friendsStore = useFriendsStore();
const activeProfile = useActiveProfileStore();
const suggestions = useFriendSuggestionsStore();
const { describe } = useErrorMessage();
const busy = ref(false);

// Opened directly (web, deep link): the friends arrive with the first sync.
useLocalRefresh(() => friendsStore.refresh());
/** An adult friend of mine, while I act in my own name. */
const canIntroduce = computed(
  () => !props.child && !activeProfile.active && friendsStore.friends.some((f) => f.user.id === props.userId && !f.user.managedBy),
);
const introduced = computed(() => suggestions.sentAbout(props.userId));

onMounted(async () => {
  if (!friendsStore.friends.length) await friendsStore.fetchFriends().catch(() => undefined);
  // Online only (spec §11 décision 35).
  if (!activeProfile.active) suggestions.fetchSent().catch(() => undefined);
});

async function run(action: () => Promise<unknown>, done?: string): Promise<void> {
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

/** All my adult friends but this one: the list doesn't tell who already knows whom (spec §11 décision 65). */
async function pickRecipients(): Promise<void> {
  const name = props.name ?? '';
  const candidates = friendsStore.friends.filter((f) => f.user.id !== props.userId && !f.user.managedBy);
  const alert = await alertController.create({
    header: t('friends.suggestions.pickTitle', { name }),
    message: candidates.length ? t('friends.suggestions.pickHint', { name }) : t('friends.suggestions.noFriendToPick', { name }),
    inputs: candidates.map((f) => ({ type: 'checkbox' as const, label: f.user.displayName ?? '', value: f.user.id })),
    buttons: [
      { text: t('common.cancel'), role: 'cancel' },
      ...(candidates.length ? [{ text: t('friends.suggestions.confirm'), role: 'confirm' }] : []),
    ],
  });
  await alert.present();
  const { role, data } = await alert.onDidDismiss<{ values?: string[] }>();
  const chosen = data?.values ?? [];
  if ('confirm' !== role || !chosen.length) return;
  await run(() => suggestions.suggest(props.userId, chosen), t('friends.suggestions.done', chosen.length));
}
</script>

<style scoped>
.introduce {
  margin: 10px 0 0;
}

.name {
  font-weight: 700;
}

ion-item ion-label p {
  color: var(--ic-text-secondary);
  font-size: 0.875rem;
}
</style>
