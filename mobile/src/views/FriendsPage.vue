<template>
  <ion-page>
    <ion-header>
      <ion-toolbar>
        <ion-title>{{ t('friends.title') }}</ion-title>
        <ion-buttons slot="end">
          <ion-button router-link="/friends/add">{{ t('friends.add') }}</ion-button>
        </ion-buttons>
      </ion-toolbar>
      <ion-toolbar>
        <ion-segment v-model="tab">
          <ion-segment-button value="friends">
            <ion-label>{{ t('friends.tabs.friends') }}</ion-label>
          </ion-segment-button>
          <ion-segment-button value="incoming">
            <ion-label>
              {{ t('friends.tabs.incoming') }}
              <ion-badge v-if="store.incoming.length" color="danger">{{ store.incoming.length }}</ion-badge>
            </ion-label>
          </ion-segment-button>
          <ion-segment-button value="outgoing">
            <ion-label>{{ t('friends.tabs.outgoing') }}</ion-label>
          </ion-segment-button>
        </ion-segment>
      </ion-toolbar>
    </ion-header>

    <ion-content class="ion-padding">
      <template v-if="'friends' === tab">
        <p v-if="!store.friends.length">{{ t('friends.empty.friends') }}</p>
        <ion-list v-else>
          <ion-item v-for="f in store.friends" :key="f.id" :router-link="`/friends/${f.user.id}`" button>
            <ion-avatar slot="start">
              <img v-if="f.user.avatarUrl" :src="f.user.avatarUrl" alt="" />
              <ion-icon v-else :icon="personCircleOutline" />
            </ion-avatar>
            <ion-label>{{ f.user.displayName }}</ion-label>
          </ion-item>
        </ion-list>
      </template>

      <template v-else-if="'incoming' === tab">
        <p v-if="!store.incoming.length">{{ t('friends.empty.incoming') }}</p>
        <ion-list v-else>
          <ion-item v-for="f in store.incoming" :key="f.id">
            <ion-avatar slot="start">
              <img v-if="f.user.avatarUrl" :src="f.user.avatarUrl" alt="" />
              <ion-icon v-else :icon="personCircleOutline" />
            </ion-avatar>
            <ion-label>{{ f.user.displayName }}</ion-label>
            <ion-buttons slot="end">
              <ion-button color="success" @click="store.accept(f.id)">{{ t('friends.accept') }}</ion-button>
              <ion-button color="danger" @click="store.decline(f.id)">{{ t('friends.decline') }}</ion-button>
            </ion-buttons>
          </ion-item>
        </ion-list>
      </template>

      <template v-else>
        <p v-if="!store.outgoing.length">{{ t('friends.empty.outgoing') }}</p>
        <ion-list v-else>
          <ion-item v-for="f in store.outgoing" :key="f.id">
            <ion-avatar slot="start">
              <img v-if="f.user.avatarUrl" :src="f.user.avatarUrl" alt="" />
              <ion-icon v-else :icon="personCircleOutline" />
            </ion-avatar>
            <ion-label>
              {{ f.user.displayName }}
              <p>{{ t(`friends.status.${f.status}`) }}</p>
            </ion-label>
            <ion-button v-if="'pending' === f.status" slot="end" fill="clear" @click="store.cancel(f.id)">
              {{ t('friends.cancel') }}
            </ion-button>
          </ion-item>
        </ion-list>
      </template>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { personCircleOutline } from 'ionicons/icons';
import {
  IonAvatar,
  IonBadge,
  IonButton,
  IonButtons,
  IonContent,
  IonHeader,
  IonIcon,
  IonItem,
  IonLabel,
  IonList,
  IonPage,
  IonSegment,
  IonSegmentButton,
  IonTitle,
  IonToolbar,
} from '@ionic/vue';
import { useFriendsStore } from '../stores/friends';

const { t } = useI18n();
const store = useFriendsStore();
const tab = ref<'friends' | 'incoming' | 'outgoing'>('friends');

onMounted(() => {
  store.fetchAll();
});
</script>
