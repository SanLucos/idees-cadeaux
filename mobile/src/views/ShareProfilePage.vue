<template>
  <ion-page>
    <ion-content>
      <TopBar :default-href="backHref">{{ owner ? t('shareLink.titleChild', { name: owner.displayName }) : t('shareLink.title') }}</TopBar>
      <p class="intro">{{ owner ? t('shareLink.introChild', { name: owner.displayName }) : t('shareLink.intro') }}</p>

      <ion-text v-if="error" color="danger"><p>{{ error }}</p></ion-text>

      <div v-if="loading" class="ic-card link-card"><ion-spinner name="dots" /></div>

      <!-- Sharing stays off until a link exists (spec §5.16). -->
      <div v-else-if="!link" class="ic-card link-card">
        <p class="ic-muted">{{ t('shareLink.none') }}</p>
        <ion-button expand="block" :disabled="busy" @click="create">{{ t('shareLink.create') }}</ion-button>
      </div>

      <div v-else class="ic-card link-card">
        <div class="link-card__head">
          <span class="status" :class="`status--${link.status}`">
            <ion-icon :icon="'active' === link.status ? checkmark : pauseCircleOutline" aria-hidden="true" />
            {{ t(`shareLink.${link.status}`) }}
          </span>
          <span class="ic-muted count">{{ t('shareLink.joinCount', { count: link.joinCount }, link.joinCount) }}</span>
        </div>
        <p v-if="'suspended' === link.status" class="suspended-hint">{{ t('shareLink.suspendedHint') }}</p>
        <div class="url" :class="{ 'url--off': 'suspended' === link.status }">{{ link.url }}</div>
        <div class="link-card__actions">
          <ion-button class="ic-button-surface" expand="block" :disabled="'active' !== link.status" @click="copy">
            <ion-icon slot="start" :icon="copyOutline" aria-hidden="true" />{{ t('shareLink.copy') }}
          </ion-button>
          <ion-button expand="block" :disabled="'active' !== link.status" @click="share">
            <ion-icon slot="start" :icon="shareOutline" aria-hidden="true" />{{ t('shareLink.share') }}
          </ion-button>
        </div>
      </div>

      <SectionTitle>{{ t('shareLink.visitorTitle') }}</SectionTitle>
      <div class="ic-card visitor">
        <ul>
          <li v-for="item in visitorSees" :key="item.key" :class="item.shown ? 'yes' : 'no'">
            <ion-icon :icon="item.shown ? checkmark : close" :color="item.shown ? 'success' : 'danger'" aria-hidden="true" />
            <span class="visually-hidden">{{ item.shown ? '✓' : '✗' }}</span>
            {{ t(`shareLink.visitorSees.${item.key}`) }}
          </li>
        </ul>
        <router-link v-if="link && 'active' === link.status" class="preview" :to="{ name: 'GuestView', params: { token: link.token }, query: { preview: '1' } }">
          {{ t('shareLink.preview') }}<ion-icon :icon="chevronForward" aria-hidden="true" />
        </router-link>
      </div>

      <template v-if="link">
        <SectionTitle>{{ t('shareLink.manageTitle') }}</SectionTitle>
        <ion-button class="ic-button-surface" expand="block" :disabled="busy" @click="regenerate">
          <ion-icon slot="start" :icon="refreshOutline" aria-hidden="true" />{{ t('shareLink.regenerate') }}
        </ion-button>
        <p class="ic-muted hint">{{ t('shareLink.regenerateHint') }}</p>
        <ion-button class="ic-button-surface" color="danger" fill="outline" expand="block" :disabled="busy" @click="disable">
          <ion-icon slot="start" :icon="close" aria-hidden="true" />{{ t('shareLink.disable') }}
        </ion-button>
      </template>
      <div class="bottom-space" />
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import { Share } from '@capacitor/share';
import { alertController, IonButton, IonContent, IonIcon, IonPage, IonSpinner, IonText, toastController } from '@ionic/vue';
import { checkmark, chevronForward, close, copyOutline, pauseCircleOutline, refreshOutline, shareOutline } from 'ionicons/icons';
import { shareLinksApi } from '../services/shareLinks';
import { useActiveProfileStore } from '../stores/activeProfile';
import { useErrorMessage } from '../composables/useErrorMessage';
import SectionTitle from '../components/SectionTitle.vue';
import TopBar from '../components/TopBar.vue';
import type { ShareLink } from '../types/shareLink';

/**
 * « Partager mon profil » (PartageLien.png, spec §5.16): my link, or a
 * child's — from its page (`/profile/children/:id/share`) or while it is
 * the active profile. Online only, like everything about friends.
 */
const { t } = useI18n();
const { describe } = useErrorMessage();
const route = useRoute();
const activeProfile = useActiveProfileStore();

const childId = computed(() => (typeof route.params.id === 'string' ? route.params.id : activeProfile.activeId));
const owner = computed(() => (childId.value ? activeProfile.children.find((c) => c.id === childId.value) ?? null : null));
const actingAs = computed(() => childId.value ?? null);
const backHref = computed(() => (typeof route.params.id === 'string' ? `/profile/children/${route.params.id}` : '/tabs/profile'));

const link = ref<ShareLink | null>(null);
const loading = ref(true);
const busy = ref(false);
const error = ref('');

const visitorSees = [
  { key: 'identity', shown: true },
  { key: 'ideas', shown: true },
  { key: 'profile', shown: false },
  { key: 'friends', shown: false },
  { key: 'drafts', shown: false },
];

onMounted(async () => {
  try {
    link.value = await shareLinksApi.mine(actingAs.value);
  } catch (e) {
    error.value = describe(e);
  } finally {
    loading.value = false;
  }
});

async function confirm(message: string, button: string, header?: string, destructive = false): Promise<boolean> {
  const alert = await alertController.create({
    header,
    message,
    buttons: [
      { text: t('common.cancel'), role: 'cancel' },
      { text: button, role: destructive ? 'destructive' : 'confirm' },
    ],
  });
  await alert.present();
  const { role } = await alert.onDidDismiss();

  return 'confirm' === role || 'destructive' === role;
}

async function run(action: () => Promise<void>): Promise<void> {
  busy.value = true;
  error.value = '';
  try {
    await action();
  } catch (e) {
    error.value = describe(e);
  } finally {
    busy.value = false;
  }
}

async function create(): Promise<void> {
  // Explicit confirmation, aimed at the child for a child's profile (spec §5.16).
  const message = owner.value ? t('shareLink.confirmCreateChild', { name: owner.value.displayName }) : t('shareLink.confirmCreate');
  if (!(await confirm(message, t('shareLink.confirmCreateButton'), t('shareLink.confirmCreateTitle')))) return;
  await run(async () => {
    link.value = await shareLinksApi.create(actingAs.value);
  });
}

async function regenerate(): Promise<void> {
  if (!(await confirm(t('shareLink.confirmRegenerate'), t('shareLink.regenerate')))) return;
  await run(async () => {
    link.value = await shareLinksApi.regenerate(actingAs.value);
  });
}

async function disable(): Promise<void> {
  if (!(await confirm(t('shareLink.confirmDisable'), t('shareLink.disable'), undefined, true))) return;
  await run(async () => {
    await shareLinksApi.disable(actingAs.value);
    link.value = null;
  });
}

async function copy(): Promise<void> {
  if (!link.value) return;
  try {
    await navigator.clipboard.writeText(link.value.url);
    const toast = await toastController.create({ message: t('shareLink.copied'), duration: 2000, position: 'bottom' });
    await toast.present();
  } catch {
    error.value = t('errors.generic');
  }
}

async function share(): Promise<void> {
  if (!link.value) return;
  const app = t('app.name');
  const text = owner.value ? t('shareLink.shareTextChild', { name: owner.value.displayName, app }) : t('shareLink.shareText', { app });
  try {
    if ((await Share.canShare()).value) {
      await Share.share({ title: t('shareLink.shareTitle'), text, url: link.value.url, dialogTitle: t('shareLink.share') });
    } else {
      await copy();
    }
  } catch {
    // Dismissing the share sheet rejects: nothing to report.
  }
}
</script>

<style scoped>
.intro {
  margin: 4px 0 16px;
  font-size: 16px;
  line-height: 1.45;
}

.link-card {
  padding: 16px;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.link-card__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.status {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 13px;
  font-weight: 700;
}

.status--active {
  background: var(--ic-success-soft);
  color: var(--ion-color-success);
}

.status--suspended {
  background: var(--ic-primary-soft);
  color: var(--ic-primary-text-on-soft);
}

.count {
  font-size: 13px;
  text-align: end;
}

.suspended-hint {
  margin: 0;
  font-size: 14px;
  color: var(--ic-primary-text-on-soft);
}

.url {
  padding: 14px;
  border-radius: var(--ic-radius-field);
  background: var(--ic-surface-muted);
  font-family: ui-monospace, 'SF Mono', Menlo, monospace;
  font-size: 15px;
  word-break: break-all;
  user-select: all;
}

.url--off {
  opacity: 0.55;
  text-decoration: line-through;
}

.link-card__actions {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}

.link-card__actions ion-button {
  margin: 0;
}

.visitor {
  padding: 12px 16px 16px;
}

.visitor ul {
  list-style: none;
  margin: 0;
  padding: 0;
}

.visitor li {
  display: flex;
  align-items: center;
  gap: 14px;
  min-height: 44px;
  font-size: 16px;
}

.visitor li ion-icon {
  font-size: 22px;
  flex-shrink: 0;
}

.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
}

.preview {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 44px;
  margin-top: 8px;
  font-weight: 700;
  color: var(--ion-color-primary);
}

.hint {
  margin: 6px 4px 12px;
  font-size: 14px;
}

.bottom-space {
  height: 32px;
}
</style>
