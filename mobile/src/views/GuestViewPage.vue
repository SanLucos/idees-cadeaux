<template>
  <ion-page>
    <ion-content>
      <TopBar v-if="preview" default-href="/profile/share">{{ t('shareLink.preview') }}</TopBar>
      <header v-else class="brand">
        <span class="brand__name"><ion-icon :icon="giftOutline" color="primary" aria-hidden="true" />{{ t('app.name') }}</span>
        <router-link class="brand__signin" to="/login">{{ t('shareLink.guest.signIn') }}</router-link>
      </header>

      <p v-if="preview" class="preview-note">{{ t('shareLink.guest.preview') }}</p>

      <div v-if="loading && !view" class="center"><ion-spinner name="dots" /></div>
      <EmptyState v-else-if="state" :icon="'offline' === state ? cloudOfflineOutline : linkOutline">
        {{ 'offline' === state ? t('shareLink.join.offline') : t('shareLink.join.invalid') }}
      </EmptyState>

      <template v-else-if="view">
        <div class="hero">
          <AppAvatar :id="token" :name="name" :url="view.owner.avatarUrl" :size="80" />
          <h1 class="ic-display">{{ t('shareLink.guest.heading', { name }) }}</h1>
          <div class="ic-muted">{{ t('shareLink.guest.readOnly') }}</div>
        </div>

        <div class="ic-chip-row" role="group">
          <ion-chip class="ic-chip" :class="{ 'ic-chip-selected': null === occasion }" :aria-pressed="null === occasion" @click="setOccasion(null)">
            {{ t('shareLink.guest.all') }}
          </ion-chip>
          <ion-chip
            v-for="code in view.occasions"
            :key="code"
            class="ic-chip"
            :class="{ 'ic-chip-selected': code === occasion }"
            :aria-pressed="code === occasion"
            @click="setOccasion(code)"
          >
            {{ te(`occasions.${code}`) ? t(`occasions.${code}`) : code }}
          </ion-chip>
          <ion-chip class="ic-chip" :class="{ 'ic-chip-selected': byPrice }" :aria-pressed="byPrice" @click="togglePrice">
            {{ t('shareLink.guest.price') }}
          </ion-chip>
        </div>

        <EmptyState v-if="!view.member.length" :icon="giftOutline">{{ t('shareLink.guest.empty', { name }) }}</EmptyState>
        <div v-else class="ic-stack">
          <GuestIdeaCard v-for="idea in view.member" :key="idea.id" :idea="idea" @interact="interact" />
        </div>
        <ion-button v-if="view.member.length < view.totalItems" fill="clear" expand="block" :disabled="loading" @click="loadMore">
          {{ t('shareLink.guest.more') }}
        </ion-button>
        <div class="bottom-space" />
      </template>
    </ion-content>

    <ion-footer v-if="!preview && view && !state" class="ion-no-border">
      <div class="cta">
        <div class="ic-muted cta__hint">{{ t('shareLink.guest.joinHint', { name }) }}</div>
        <ion-button expand="block" router-link="/register">{{ t('shareLink.guest.createAccount') }}</ion-button>
        <ion-button class="ic-button-surface" expand="block" router-link="/login">{{ t('shareLink.guest.haveAccount') }}</ion-button>
      </div>
    </ion-footer>
  </ion-page>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { alertController, IonButton, IonChip, IonContent, IonFooter, IonIcon, IonPage, IonSpinner } from '@ionic/vue';
import { cloudOfflineOutline, giftOutline, linkOutline } from 'ionicons/icons';
import { ApiError, isNetworkError } from '../services/api';
import { shareLinksApi } from '../services/shareLinks';
import { usePendingInvitationStore } from '../stores/pendingInvitation';
import AppAvatar from '../components/AppAvatar.vue';
import EmptyState from '../components/EmptyState.vue';
import GuestIdeaCard from '../components/GuestIdeaCard.vue';
import TopBar from '../components/TopBar.vue';
import type { GuestView } from '../types/shareLink';

/**
 * The guest view in the app (VueInvite.png, spec §5.16): a share link
 * opened without an account — or the owner's « Aperçu » (`?preview=1`).
 * Only what the server's guest view returns; every interaction leads to
 * « Créer un compte pour interagir », and the invitation is kept for
 * the confirmation screen after sign-up (stores/pendingInvitation).
 */
const { t, te } = useI18n();
const route = useRoute();
const router = useRouter();
const pending = usePendingInvitationStore();

const token = computed(() => String(route.params.token));
const preview = computed(() => '1' === route.query.preview);

const view = ref<GuestView | null>(null);
const loading = ref(false);
const state = ref<'invalid' | 'offline' | null>(null);
const occasion = ref<string | null>(null);
const byPrice = ref(false);
const page = ref(1);

const name = computed(() => view.value?.owner.displayName ?? '');

onMounted(() => {
  if (!preview.value) pending.keep(token.value);
  void load();
});

async function load(append = false): Promise<void> {
  loading.value = true;
  try {
    const result = await shareLinksApi.guestView(token.value, { occasion: occasion.value, sort: byPrice.value ? 'price_asc' : 'recent', page: page.value });
    view.value = append && view.value ? { ...result, member: [...view.value.member, ...result.member] } : result;
    state.value = null;
  } catch (e) {
    if (isNetworkError(e)) {
      // Kept (pending invitation): handled once the network is back.
      state.value = 'offline';
    } else if (e instanceof ApiError && 404 === e.status) {
      state.value = 'invalid';
      if (!preview.value) pending.clear();
    } else {
      throw e;
    }
  } finally {
    loading.value = false;
  }
}

function setOccasion(code: string | null): void {
  occasion.value = code;
  page.value = 1;
  void load();
}

function togglePrice(): void {
  byPrice.value = !byPrice.value;
  page.value = 1;
  void load();
}

function loadMore(): void {
  page.value += 1;
  void load(true);
}

async function interact(): Promise<void> {
  if (preview.value) return;
  const alert = await alertController.create({
    header: t('shareLink.guest.interactTitle'),
    message: t('shareLink.guest.interactText'),
    buttons: [
      { text: t('shareLink.guest.haveAccount'), handler: () => void router.push('/login') },
      { text: t('shareLink.guest.createAccount'), handler: () => void router.push('/register') },
    ],
  });
  await alert.present();
}
</script>

<style scoped>
.brand {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: calc(var(--ion-safe-area-top, 0px) + 16px) 0 8px;
}

.brand__name {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-family: var(--ic-font-display);
  font-size: 18px;
}

.brand__name ion-icon {
  font-size: 22px;
}

.brand__signin {
  display: inline-flex;
  align-items: center;
  min-height: 44px;
  font-weight: 600;
  color: var(--ion-color-primary);
}

.preview-note {
  margin: 0 0 8px;
  padding: 10px 12px;
  border-radius: var(--ic-radius-field);
  background: var(--ic-primary-soft);
  color: var(--ic-primary-text-on-soft);
  font-size: 14px;
}

.hero {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  padding: 12px 0 16px;
  text-align: center;
}

.hero h1 {
  margin: 0;
  font-size: 28px;
  line-height: 1.15;
}

.center {
  display: flex;
  justify-content: center;
  padding: 48px 0;
}

.cta {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 16px var(--ic-gutter) calc(var(--ion-safe-area-bottom, 0px) + 16px);
  background: var(--ic-surface);
  border-top: 1px solid var(--ic-border);
}

.cta ion-button {
  margin: 0;
}

.cta__hint {
  text-align: center;
  font-size: 14px;
}

.bottom-space {
  height: 24px;
}
</style>
