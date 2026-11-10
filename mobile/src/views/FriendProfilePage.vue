<template>
  <ion-page>
    <ion-content>
      <TopBar :default-href="`/tabs/friends/${userId}`">{{ profile ? t('friends.profile.title', { name: profile.displayName }) : '' }}</TopBar>

      <template v-if="profile">
        <div class="identity">
          <AppAvatar :id="profile.id" :name="profile.displayName" :url="profile.avatarUrl" :size="72" />
          <div>
            <div class="identity__name">{{ profile.displayName }}</div>
            <div v-if="profile.birthDay && profile.birthMonth" class="ic-muted">
              {{ t('profile.birthdayOn', { date: formatBirthday(profile.birthDay, profile.birthMonth, locale) }) }}
            </div>
          </div>
        </div>

        <ProfileSizesSection :entries="sizes" />
        <ProfilePreferencesSection :entries="preferences" />

        <!-- Spec §11 décision 65: adults only, in my own name. -->
        <template v-if="canIntroduce">
          <ion-button expand="block" class="ic-button-surface ion-margin-top" :disabled="busy" data-cy="introduce" @click="pickRecipients">
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

        <!-- Spec §11 décision 59: the child's side ends « via vos amis » ones all at once, with the option. -->
        <ion-button v-if="!sharedByMyFamily" expand="block" color="danger" fill="clear" class="ion-margin-top" @click="confirmRemove">
          {{ t('friends.remove') }}
        </ion-button>
      </template>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { alertController, IonButton, IonContent, IonIcon, IonItem, IonLabel, IonList, IonPage, toastController } from '@ionic/vue';
import { peopleOutline } from 'ionicons/icons';
import SectionTitle from '../components/SectionTitle.vue';
import { useActiveProfileStore } from '../stores/activeProfile';
import { useFriendSuggestionsStore } from '../stores/friendSuggestions';
import { useErrorMessage } from '../composables/useErrorMessage';
import { useLocalRefresh } from '../composables/useLocalRefresh';
import AppAvatar from '../components/AppAvatar.vue';
import ProfilePreferencesSection from '../components/ProfilePreferencesSection.vue';
import ProfileSizesSection from '../components/ProfileSizesSection.vue';
import TopBar from '../components/TopBar.vue';
import { formatBirthday } from '../utils/birthday';
import { api } from '../services/api';
import { repo } from '../offline/repo';
import { useFriendsStore } from '../stores/friends';
import { useProfileDetailsStore } from '../stores/profileDetails';
import type { ProfilePreference, ProfileSize } from '../types/profile';

interface FriendProfile {
  id: string;
  displayName: string | null;
  avatarUrl: string | null;
  birthDay: number | null;
  birthMonth: number | null;
  birthYear: number | null;
  /** A child profile: its managers (spec §5.15). */
  managedBy?: { id: string; displayName: string | null } | null;
}

const { t, locale } = useI18n();
const route = useRoute();
const router = useRouter();
const friendsStore = useFriendsStore();
const profileDetails = useProfileDetailsStore();

const userId = String(route.params.id);
const profile = ref<FriendProfile | null>(null);
const sizes = ref<ProfileSize[]>([]);
const preferences = ref<ProfilePreference[]>([]);
const activeProfile = useActiveProfileStore();
const suggestions = useFriendSuggestionsStore();
const { describe } = useErrorMessage();
const busy = ref(false);
// Opened directly (web, deep link): the friends arrive with the first sync.
useLocalRefresh(() => friendsStore.refresh());
/** An adult friend of mine, while I act in my own name. */
const canIntroduce = computed(() => !!profile.value && !profile.value.managedBy && !activeProfile.active && friendsStore.friends.some((f) => f.user.id === userId));
const introduced = computed(() => suggestions.sentAbout(userId));
const sharedByMyFamily = computed(() => 'family' === friendsStore.friends.find((f) => f.user.id === userId)?.origin && !profile.value?.managedBy);

onMounted(async () => {
  profile.value = (repo.user(userId) as FriendProfile | null) ?? (await api.get<FriendProfile>(`/users/${userId}`));
  sizes.value = await profileDetails.fetchSizesFor(userId);
  preferences.value = await profileDetails.fetchPreferencesFor(userId);
  if (!friendsStore.friends.length) {
    await friendsStore.fetchFriends();
  }
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
  const name = profile.value?.displayName ?? '';
  const candidates = friendsStore.friends.filter((f) => f.user.id !== userId && !f.user.managedBy);
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
  await run(() => suggestions.suggest(userId, chosen), t('friends.suggestions.done', chosen.length));
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
          const friendship = friendsStore.friends.find((f) => f.user.id === userId);
          if (friendship) {
            await friendsStore.remove(friendship.id);
          }
          router.replace('/tabs/friends');
        },
      },
    ],
  });
  await alert.present();
}
</script>

<style scoped>
.name {
  font-weight: 700;
}

ion-item ion-label p {
  color: var(--ic-text-secondary);
  font-size: 0.875rem;
}

.identity {
  display: flex;
  align-items: center;
  gap: 14px;
}

.identity__name {
  font-family: var(--ic-font-display);
  font-size: 1.625rem;
}
</style>
