<template>
  <ion-page>
    <ion-header>
      <ion-toolbar>
        <ion-title>{{ profile ? t('friends.profile.title', { name: profile.displayName }) : '' }}</ion-title>
      </ion-toolbar>
    </ion-header>
    <ion-content class="ion-padding" v-if="profile">
      <div class="ion-text-center">
        <ion-avatar style="width: 96px; height: 96px; margin: 0 auto;">
          <img v-if="profile.avatarUrl" :src="profile.avatarUrl" alt="" />
          <ion-icon v-else :icon="personCircleOutline" style="width: 100%; height: 100%;" />
        </ion-avatar>
        <h1>{{ profile.displayName }}</h1>
        <p v-if="profile.birthDay && profile.birthMonth">
          {{ profile.birthDay }}/{{ profile.birthMonth }}<span v-if="profile.birthYear">/{{ profile.birthYear }}</span>
        </p>
      </div>

      <h2>{{ t('profile.sizes.title') }}</h2>
      <p v-if="!sizes.length">{{ t('profile.sizes.empty') }}</p>
      <ion-list v-else>
        <ion-item v-for="size in sizes" :key="size['@id']">
          <ion-label>
            <h3>{{ size.label }}</h3>
            <p>{{ size.value }}<span v-if="size.note"> — {{ size.note }}</span></p>
          </ion-label>
        </ion-item>
      </ion-list>

      <h2>{{ t('profile.preferences.title') }}</h2>
      <p v-if="!preferences.length">{{ t('profile.preferences.empty') }}</p>
      <ion-list v-else>
        <ion-item v-for="pref in preferences" :key="pref['@id']">
          <ion-label>
            <h3>{{ pref.label }}</h3>
            <p>{{ t(`profile.preferences.category_${pref.category}`) }} — {{ pref.value }}</p>
          </ion-label>
        </ion-item>
      </ion-list>

      <ion-button expand="block" color="danger" fill="outline" class="ion-margin-top" @click="confirmRemove">
        {{ t('friends.remove') }}
      </ion-button>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { personCircleOutline } from 'ionicons/icons';
import { alertController, IonAvatar, IonButton, IonContent, IonHeader, IonIcon, IonItem, IonLabel, IonList, IonPage, IonTitle, IonToolbar } from '@ionic/vue';
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

const { t } = useI18n();
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
          router.replace('/friends');
        },
      },
    ],
  });
  await alert.present();
}
</script>
