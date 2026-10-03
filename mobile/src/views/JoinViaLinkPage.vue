<template>
  <ion-page>
    <ion-content>
      <div class="close">
        <ion-button class="ic-round-button" :aria-label="t('common.close')" @click="later">
          <ion-icon aria-hidden="true" slot="icon-only" :icon="closeOutline" />
        </ion-button>
      </div>

      <div v-if="!invitation && !state" class="center"><ion-spinner name="dots" /></div>
      <EmptyState v-else-if="state" :icon="'offline' === state ? cloudOfflineOutline : linkOutline">
        {{ 'offline' === state ? t('shareLink.join.offline') : t('shareLink.join.invalid') }}
      </EmptyState>

      <div v-else-if="invitation" class="confirm">
        <div class="avatars" aria-hidden="true">
          <AppAvatar :id="auth.user?.id ?? ''" :name="auth.user?.displayName" :url="auth.user?.avatarUrl" :size="88" primary />
          <AppAvatar class="avatars__other" :id="invitation.owner.id" :name="ownerName" :url="invitation.owner.avatarUrl" :size="88" />
        </div>
        <h1 class="ic-display">{{ t('shareLink.join.title', { name: ownerName }) }}</h1>
        <p class="ic-muted">{{ t('shareLink.join.text', { name: ownerName }) }}</p>
        <!-- « profil géré par [gestionnaire] » (spec §5.16). -->
        <p v-if="invitation.owner.managedBy" class="ic-muted">{{ t('shareLink.join.textChild', { manager: invitation.owner.managedBy.displayName }) }}</p>
        <ion-text v-if="error" color="danger"><p>{{ error }}</p></ion-text>
      </div>
    </ion-content>

    <ion-footer v-if="invitation && !state" class="ion-no-border">
      <div class="actions">
        <ion-button expand="block" :disabled="joining" @click="join">
          <ion-icon slot="start" :icon="personAddOutline" aria-hidden="true" />{{ t('shareLink.join.confirm') }}
        </ion-button>
        <ion-button class="ic-button-surface" expand="block" @click="later">{{ t('shareLink.join.later') }}</ion-button>
      </div>
    </ion-footer>
  </ion-page>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import type { PluginListenerHandle } from '@capacitor/core';
import { Network } from '@capacitor/network';
import { IonButton, IonContent, IonFooter, IonIcon, IonPage, IonSpinner, IonText, toastController, useIonRouter } from '@ionic/vue';
import { closeOutline, cloudOfflineOutline, linkOutline, personAddOutline } from 'ionicons/icons';
import { ApiError, isNetworkError } from '../services/api';
import { shareLinksApi } from '../services/shareLinks';
import { useActiveProfileStore } from '../stores/activeProfile';
import { useAuthStore } from '../stores/auth';
import { useFriendsStore } from '../stores/friends';
import { usePendingInvitationStore } from '../stores/pendingInvitation';
import { useErrorMessage } from '../composables/useErrorMessage';
import AppAvatar from '../components/AppAvatar.vue';
import EmptyState from '../components/EmptyState.vue';
import type { ShareLinkInvitation } from '../types/shareLink';

/**
 * « Devenir ami avec X ? » (ConfirmationAmi.png, spec §5.16): a share
 * link opened while signed in. Nothing happens without the explicit
 * confirmation. My own link, my child's, or a friend's lead straight to
 * the matching screen. Offline, the invitation is kept and retried when
 * the network comes back.
 */
const { t } = useI18n();
const { describe } = useErrorMessage();
const route = useRoute();
const ionRouter = useIonRouter();
const auth = useAuthStore();
const activeProfile = useActiveProfileStore();
const friends = useFriendsStore();
const pending = usePendingInvitationStore();

const token = computed(() => String(route.params.token));
const invitation = ref<ShareLinkInvitation | null>(null);
const state = ref<'invalid' | 'offline' | null>(null);
const joining = ref(false);
const error = ref('');
const ownerName = computed(() => invitation.value?.owner.displayName ?? '');

let networkListener: PluginListenerHandle | null = null;

onMounted(async () => {
  pending.keep(token.value);
  networkListener = await Network.addListener('networkStatusChange', ({ connected }) => {
    if (connected && 'offline' === state.value) void load();
  });
  await load();
});

onUnmounted(() => {
  void networkListener?.remove();
});

function leaveTo(path: string): void {
  pending.clear();
  ionRouter.navigate(path, 'root', 'replace');
}

function redirect(result: ShareLinkInvitation): boolean {
  switch (result.relation) {
    case 'self':
      leaveTo('/tabs/profile');
      return true;
    case 'manager':
      leaveTo(`/profile/children/${result.owner.id}`);
      return true;
    case 'friend':
      // The friendship is mine (adults only join), not the active child's.
      activeProfile.switchTo(null);
      leaveTo(`/tabs/friends/${result.owner.id}`);
      return true;
    default:
      return false;
  }
}

function fail(e: unknown): void {
  if (isNetworkError(e)) {
    state.value = 'offline';
  } else if (e instanceof ApiError && 404 === e.status) {
    state.value = 'invalid';
    pending.clear();
  } else {
    error.value = describe(e);
  }
}

async function load(): Promise<void> {
  error.value = '';
  try {
    const result = await shareLinksApi.invitation(token.value);
    state.value = null;
    if (!redirect(result)) invitation.value = result;
  } catch (e) {
    fail(e);
  }
}

async function join(): Promise<void> {
  joining.value = true;
  error.value = '';
  try {
    const result = await shareLinksApi.join(token.value);
    void friends.fetchAll();
    const name = ownerName.value;
    redirect(result);
    if ('friend' === result.relation && result.friendshipId) {
      void toastController
        .create({ message: t('shareLink.join.done', { name }), duration: 2500, position: 'bottom' })
        .then((toast) => toast.present());
    }
  } catch (e) {
    fail(e);
  } finally {
    joining.value = false;
  }
}

function later(): void {
  if ('offline' === state.value) {
    // Kept for when the network is back (services/shareIntake.ts).
    pending.defer();
    ionRouter.navigate('/tabs/list', 'root', 'replace');
    return;
  }
  leaveTo('/tabs/list');
}
</script>

<style scoped>
.close {
  display: flex;
  justify-content: flex-end;
  padding-top: calc(var(--ion-safe-area-top, 0px) + 12px);
}

.center {
  display: flex;
  justify-content: center;
  padding: 48px 0;
}

.confirm {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  padding-top: 18vh;
}

.avatars {
  display: flex;
  margin-bottom: 20px;
}

.avatars__other {
  margin-left: -16px;
  box-shadow: 0 0 0 4px var(--ion-background-color);
}

.confirm h1 {
  margin: 0 0 12px;
  font-size: 1.875rem;
  line-height: 1.2;
}

.confirm p {
  margin: 0 0 8px;
  font-size: 1rem;
  line-height: 1.5;
}

.actions {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 12px var(--ic-gutter) calc(var(--ion-safe-area-bottom, 0px) + 16px);
}

.actions ion-button {
  margin: 0;
}
</style>
