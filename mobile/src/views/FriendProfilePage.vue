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

        <ion-button expand="block" color="danger" fill="clear" class="ion-margin-top" @click="confirmRemove">
          {{ t('friends.remove') }}
        </ion-button>
      </template>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { alertController, IonButton, IonContent, IonPage } from '@ionic/vue';
import AppAvatar from '../components/AppAvatar.vue';
import ProfilePreferencesSection from '../components/ProfilePreferencesSection.vue';
import ProfileSizesSection from '../components/ProfileSizesSection.vue';
import TopBar from '../components/TopBar.vue';
import { formatBirthday } from '../utils/birthday';
import { api } from '../services/api';
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

onMounted(async () => {
  profile.value = await api.get<FriendProfile>(`/users/${userId}`);
  sizes.value = await profileDetails.fetchSizesFor(userId);
  preferences.value = await profileDetails.fetchPreferencesFor(userId);
  if (!friendsStore.friends.length) {
    await friendsStore.fetchFriends();
  }
});

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
.identity {
  display: flex;
  align-items: center;
  gap: 14px;
}

.identity__name {
  font-family: var(--ic-font-display);
  font-size: 26px;
}
</style>
