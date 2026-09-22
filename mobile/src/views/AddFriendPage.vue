<template>
  <ion-page>
    <ion-header>
      <ion-toolbar>
        <ion-title>{{ t('friends.add_screen.title') }}</ion-title>
      </ion-toolbar>
    </ion-header>
    <ion-content class="ion-padding">
      <p>{{ t('friends.add_screen.instructions') }}</p>

      <form @submit.prevent="submit">
        <ion-list>
          <ion-item>
            <ion-input v-model="email" :label="t('auth.email')" label-placement="stacked" type="email" required />
          </ion-item>
        </ion-list>

        <ion-text color="success" v-if="sent"><p>{{ t('friends.add_screen.sent') }}</p></ion-text>
        <ion-text color="danger" v-if="error"><p>{{ error }}</p></ion-text>

        <ion-button expand="block" type="submit" class="ion-margin-top" :disabled="loading">
          {{ t('friends.add_screen.submit') }}
        </ion-button>
      </form>

      <h2 class="ion-margin-top">{{ t('friends.add_screen.contactsTitle') }}</h2>
      <ion-button expand="block" fill="outline" :disabled="matchingContacts" @click="matchContacts">
        {{ t('friends.add_screen.contactsButton') }}
      </ion-button>

      <ion-text color="danger" v-if="contactsError"><p>{{ contactsError }}</p></ion-text>

      <ion-list v-if="store.contactMatches.length">
        <ion-item v-for="match in store.contactMatches" :key="match.id">
          <ion-label>{{ match.displayName }}</ion-label>
          <ion-button
            slot="end"
            fill="clear"
            :disabled="requestedContactIds.has(match.id)"
            @click="sendToContact(match.id)"
          >
            {{ requestedContactIds.has(match.id) ? t('friends.add_screen.sent') : t('friends.add') }}
          </ion-button>
        </ion-item>
      </ion-list>
      <p v-else-if="contactsSearched">{{ t('friends.add_screen.contactsEmpty') }}</p>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { IonButton, IonContent, IonHeader, IonInput, IonItem, IonLabel, IonList, IonPage, IonText, IonTitle, IonToolbar } from '@ionic/vue';
import { useFriendsStore } from '../stores/friends';
import { useErrorMessage } from '../composables/useErrorMessage';
import { ContactsPermissionDeniedError } from '../services/contactsMatch';

const { t } = useI18n();
const store = useFriendsStore();
const { describe } = useErrorMessage();

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
