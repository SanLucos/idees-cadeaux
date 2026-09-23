<template>
  <ion-page>
    <ion-content>
      <TopBar default-href="/tabs/friends">{{ t('friends.add_screen.title') }}</TopBar>

      <form class="ic-card block" @submit.prevent="submit">
        <p class="ic-muted">{{ t('friends.add_screen.instructions') }}</p>
        <ion-input
          v-model="email"
          class="ic-field"
          fill="outline"
          :label="t('auth.email')"
          label-placement="stacked"
          type="email"
          required
        />
        <p v-if="sent" class="feedback feedback--ok">{{ t('friends.add_screen.sent') }}</p>
        <ion-text v-if="error" color="danger"><p>{{ error }}</p></ion-text>
        <ion-button expand="block" type="submit" :disabled="loading">{{ t('friends.add_screen.submit') }}</ion-button>
      </form>

      <SectionTitle>{{ t('friends.add_screen.contactsTitle') }}</SectionTitle>
      <ion-button class="ic-button-surface" expand="block" :disabled="matchingContacts" @click="matchContacts">
        <ion-icon slot="start" :icon="bookOutline" />
        {{ t('friends.add_screen.contactsButton') }}
      </ion-button>

      <ion-text v-if="contactsError" color="danger"><p>{{ contactsError }}</p></ion-text>

      <ion-list v-if="store.contactMatches.length" class="ic-card-list ion-margin-top" lines="inset">
        <ion-item v-for="match in store.contactMatches" :key="match.id">
          <AppAvatar slot="start" :id="match.id" :name="match.displayName" :size="40" />
          <ion-label>{{ match.displayName }}</ion-label>
          <ion-button
            slot="end"
            fill="clear"
            :disabled="requestedContactIds.has(match.id)"
            @click="sendToContact(match.id)"
          >
            {{ requestedContactIds.has(match.id) ? t('friends.add_screen.requested') : t('friends.add') }}
          </ion-button>
        </ion-item>
      </ion-list>
      <EmptyState v-else-if="contactsSearched">{{ t('friends.add_screen.contactsEmpty') }}</EmptyState>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import { bookOutline } from 'ionicons/icons';
import { IonButton, IonContent, IonIcon, IonInput, IonItem, IonLabel, IonList, IonPage, IonText } from '@ionic/vue';
import AppAvatar from '../components/AppAvatar.vue';
import EmptyState from '../components/EmptyState.vue';
import SectionTitle from '../components/SectionTitle.vue';
import TopBar from '../components/TopBar.vue';
import { useFriendsStore } from '../stores/friends';
import { useErrorMessage } from '../composables/useErrorMessage';
import { ContactsPermissionDeniedError } from '../services/contactsMatch';

const { t } = useI18n();
const store = useFriendsStore();
const { describe } = useErrorMessage();
const route = useRoute();

onMounted(() => {
  // "Mes contacts" on the friends screen lands here and starts matching straight away.
  if ('1' === route.query.contacts) {
    matchContacts();
  }
});

const email = ref('');
const loading = ref(false);
const sent = ref(false);
const error = ref('');

async function submit(): Promise<void> {
  loading.value = true;
  error.value = '';
  sent.value = false;
  try {
    await store.sendRequest(email.value);
    sent.value = true;
    email.value = '';
  } catch (e) {
    error.value = describe(e);
  } finally {
    loading.value = false;
  }
}

const matchingContacts = ref(false);
const contactsSearched = ref(false);
const contactsError = ref('');
const requestedContactIds = ref(new Set<string>());

async function sendToContact(userId: string): Promise<void> {
  try {
    await store.sendRequestToUser(userId);
    requestedContactIds.value.add(userId);
  } catch (e) {
    contactsError.value = describe(e);
  }
}

async function matchContacts(): Promise<void> {
  matchingContacts.value = true;
  contactsError.value = '';
  try {
    await store.matchContacts();
    contactsSearched.value = true;
  } catch (e) {
    contactsError.value = e instanceof ContactsPermissionDeniedError ? t('friends.add_screen.contactsDenied') : describe(e);
  } finally {
    matchingContacts.value = false;
  }
}
</script>

<style scoped>
.block {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 16px;
}

.block p {
  margin: 0;
}

.feedback--ok {
  color: var(--ion-color-success);
  font-weight: 600;
}
</style>
