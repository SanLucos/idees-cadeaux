<template>
  <ion-header>
    <ion-toolbar>
      <ion-title>{{ card?.displayName ?? '…' }}</ion-title>
      <ion-buttons slot="end">
        <ion-button :aria-label="t('common.close')" @click="emit('close')">
          <ion-icon slot="icon-only" :icon="closeOutline" aria-hidden="true" />
        </ion-button>
      </ion-buttons>
    </ion-toolbar>
  </ion-header>

  <ion-content class="ion-padding" data-cy="admin-card">
    <template v-if="card">
      <div class="who">
        <AppAvatar :id="card.id" :name="card.displayName" :url="card.avatarUrl" :size="56" />
        <div>
          <div class="ic-muted">{{ card.email ?? '—' }} · {{ t(`admin.type.${card.type}`) }} · {{ date(card.createdAt) }}</div>
          <div class="tags">
            <span v-for="key in signInKeys(card)" :key="key" class="tag">{{ t(`admin.signIn.${key}`) }}</span>
            <span v-if="card.email && !card.emailVerified" class="tag tag--warn">{{ t('admin.unverified') }}</span>
            <span v-if="card.deletionScheduledAt" class="tag tag--danger">{{ t('admin.deleting') }}</span>
          </div>
          <div v-if="card.managers.length" class="ic-muted small">
            {{ t('admin.managedBy', { names: joinNames(card.managers.map((m) => m.displayName), locale) }) }}
          </div>
        </div>
      </div>

      <template v-if="card.children.length">
        <SectionTitle>{{ t('admin.card.children') }}</SectionTitle>
        <ion-list class="ic-card-list" lines="inset">
          <ion-item v-for="c in card.children" :key="c.id" button :detail="false" @click="emit('open', c.id)">
            <AppAvatar slot="start" :id="c.id" :name="c.displayName" :url="c.avatarUrl" :size="32" />
            <ion-label>{{ c.displayName }}</ion-label>
          </ion-item>
        </ion-list>
      </template>

      <SectionTitle>{{ t('admin.card.friends', { count: card.friends.length }) }}</SectionTitle>
      <ion-list v-if="card.friends.length" class="ic-card-list" lines="inset">
        <ion-item v-for="f in card.friends" :key="f.friendshipId" data-cy="admin-friend">
          <AppAvatar slot="start" :id="f.user.id" :name="f.user.displayName" :url="f.user.avatarUrl" :size="32" />
          <ion-label>
            <button type="button" class="link" @click="emit('open', f.user.id)">{{ f.user.displayName }}</button>
            <p>
              <span class="tag" :class="{ 'tag--admin': 'admin' === f.origin }">{{ t(`admin.origin.${f.origin}`) }}</span>
              <template v-if="f.since"> {{ t('admin.card.since', { date: date(f.since) }) }}</template>
            </p>
          </ion-label>
          <ion-button
            v-if="removable(f)"
            slot="end"
            fill="clear"
            color="danger"
            :disabled="busy"
            data-cy="admin-remove-friend"
            @click="remove(f)"
          >
            {{ t('admin.card.remove') }}
          </ion-button>
        </ion-item>
      </ion-list>
      <p v-else class="ic-muted">{{ t('admin.card.none') }}</p>

      <template v-if="card.requests.length">
        <SectionTitle>{{ t('admin.card.requests') }}</SectionTitle>
        <ion-list class="ic-card-list" lines="inset">
          <ion-item v-for="r in card.requests" :key="r.user.id + r.createdAt">
            <AppAvatar slot="start" :id="r.user.id" :name="r.user.displayName" :url="r.user.avatarUrl" :size="32" />
            <ion-label>
              {{ r.user.displayName }}
              <p>{{ t(`admin.card.${r.direction}`) }} · {{ date(r.createdAt) }}</p>
            </ion-label>
          </ion-item>
        </ion-list>
      </template>

      <template v-if="card.introductions.length">
        <SectionTitle>{{ t('admin.card.introductions') }}</SectionTitle>
        <ion-list class="ic-card-list" lines="inset">
          <ion-item v-for="i in card.introductions" :key="i.id" data-cy="admin-introduction">
            <AppAvatar slot="start" :id="i.user.id" :name="i.user.displayName" :url="i.user.avatarUrl" :size="32" />
            <ion-label>
              {{ i.user.displayName }}
              <p>{{ t('admin.card.expires', { date: date(i.expiresAt) }) }}</p>
            </ion-label>
            <ion-button slot="end" fill="clear" :disabled="busy" @click="cancelIntroduction(i.id)">{{ t('admin.card.cancelIntroduction') }}</ion-button>
          </ion-item>
        </ion-list>
      </template>

      <template v-if="connectable">
        <SectionTitle>{{ t('admin.card.add') }}</SectionTitle>
        <ion-searchbar
          v-model="search"
          class="search"
          :placeholder="t('admin.card.searchPlaceholder')"
          :debounce="300"
          data-cy="admin-card-search"
          @ion-input="find"
        />
        <ion-list v-if="candidates.length" class="ic-card-list" lines="inset">
          <ion-item v-for="u in candidates" :key="u.id" data-cy="admin-candidate">
            <AppAvatar slot="start" :id="u.id" :name="u.displayName" :url="u.avatarUrl" :size="32" />
            <ion-label>
              {{ u.displayName ?? '—' }}
              <p>{{ u.email }}</p>
            </ion-label>
            <div slot="end" class="actions">
              <template v-if="canConnect(u)">
                <ion-button
                  v-if="'introduced' !== u.relation"
                  size="small"
                  class="ic-button-surface"
                  :disabled="busy"
                  data-cy="admin-candidate-introduce"
                  @click="connect(u, 'introduce')"
                >
                  {{ t('admin.modes.introduce') }}
                </ion-button>
                <ion-button size="small" color="dark" :disabled="busy" data-cy="admin-candidate-force" @click="connect(u, 'force')">
                  {{ t('admin.modes.force') }}
                </ion-button>
              </template>
              <span v-if="u.relation && 'available' !== u.relation" class="ic-muted small">{{ t(`admin.relation.${u.relation}`) }}</span>
            </div>
          </ion-item>
        </ion-list>
      </template>
    </template>
  </ion-content>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  alertController,
  IonButton,
  IonButtons,
  IonContent,
  IonHeader,
  IonIcon,
  IonItem,
  IonLabel,
  IonList,
  IonSearchbar,
  IonTitle,
  IonToolbar,
  toastController,
} from '@ionic/vue';
import { closeOutline } from 'ionicons/icons';
import AppAvatar from './AppAvatar.vue';
import SectionTitle from './SectionTitle.vue';
import { adminApi } from '../services/admin';
import { useErrorMessage } from '../composables/useErrorMessage';
import { isConnectable, signInKeys } from '../utils/admin';
import { joinNames } from '../utils/names';
import type { AdminMode, AdminUser, AdminUserCard } from '../types/admin';

/**
 * One account in the back-office (spec §5.17): its friends, pending
 * requests and introductions, and a search to connect it to others.
 * Never an idea nor anything done on one.
 */
const props = defineProps<{ userId: string }>();
const emit = defineEmits<{ close: []; changed: []; open: [id: string] }>();

const { t, locale } = useI18n();
const { describe } = useErrorMessage();

const card = ref<AdminUserCard | null>(null);
const search = ref('');
const candidates = ref<AdminUser[]>([]);
const busy = ref(false);

const connectable = computed(() => null !== card.value && isConnectable(card.value));

watch(() => props.userId, () => {
  search.value = '';
  candidates.value = [];
  void load();
}, { immediate: true });

async function load(): Promise<void> {
  try {
    card.value = await adminApi.user(props.userId);
  } catch (e) {
    await toast(describe(e), 'danger');
  }
}

async function find(): Promise<void> {
  if (!search.value.trim()) {
    candidates.value = [];

    return;
  }
  try {
    const result = await adminApi.users({ q: search.value, type: 'regular', relativeTo: props.userId });
    candidates.value = result.items.filter((u) => u.id !== props.userId);
  } catch (e) {
    await toast(describe(e), 'danger');
  }
}

function canConnect(u: AdminUser): boolean {
  return null !== u.relation && ['available', 'request_pending', 'introduced'].includes(u.relation);
}

/** « Famille » friendships follow « Partager avec mes amis », never the administrator. */
function removable(f: AdminUserCard['friends'][number]): boolean {
  return 'family' !== f.origin && 'regular' === card.value?.type && 'regular' === f.user.type;
}

async function connect(u: AdminUser, mode: AdminMode): Promise<void> {
  await act(async () => {
    const { done, unavailable } = await adminApi.connect([props.userId, u.id], mode);
    await toast(t('admin.result', { done, unavailable }, done), 'success');
    await Promise.all([load(), find()]);
  });
}

async function remove(f: AdminUserCard['friends'][number]): Promise<void> {
  const alert = await alertController.create({
    message: t('admin.card.removeConfirm', { a: card.value?.displayName ?? '', b: f.user.displayName ?? '' }),
    buttons: [
      { text: t('admin.cancel'), role: 'cancel' },
      { text: t('admin.card.remove'), role: 'destructive' },
    ],
  });
  await alert.present();
  if ('destructive' !== (await alert.onDidDismiss()).role) return;

  await act(async () => {
    await adminApi.removeFriendship(f.friendshipId);
    await toast(t('admin.card.removed'), 'success');
    await load();
  });
}

async function cancelIntroduction(id: string): Promise<void> {
  await act(async () => {
    await adminApi.cancelIntroduction(id);
    await toast(t('admin.card.cancelled'), 'success');
    await load();
  });
}

async function act(action: () => Promise<void>): Promise<void> {
  busy.value = true;
  try {
    await action();
    emit('changed');
  } catch (e) {
    await toast(describe(e), 'danger');
  } finally {
    busy.value = false;
  }
}

function date(iso: string): string {
  return new Intl.DateTimeFormat(locale.value, { dateStyle: 'short' }).format(new Date(iso));
}

async function toast(message: string, color: 'success' | 'danger'): Promise<void> {
  await (await toastController.create({ message, duration: 3000, color })).present();
}
</script>

<style scoped>
.who {
  display: flex;
  gap: 12px;
  align-items: center;
}

.tags {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin: 6px 0;
}

.tag {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
  background: var(--ic-surface-muted);
  color: var(--ion-text-color);
}

.tag--admin {
  background: var(--ic-primary-soft);
  color: var(--ic-primary-text-on-soft);
}

.tag--warn {
  color: var(--ion-color-warning-shade);
}

.tag--danger {
  color: var(--ion-color-danger);
}

.small {
  font-size: 12px;
}

.link {
  padding: 0;
  border: 0;
  background: none;
  font: inherit;
  color: inherit;
  text-decoration: underline;
  cursor: pointer;
}

.search {
  padding: 0;
  --background: var(--ic-surface);
  --border-radius: 12px;
}

.actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  gap: 4px;
}
</style>
