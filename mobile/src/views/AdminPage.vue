<template>
  <ion-page class="ic-wide">
    <ion-content>
      <TopBar default-href="/tabs/profile">{{ t('admin.title') }}</TopBar>

      <div class="admin">
        <ion-searchbar
          v-model="query"
          class="admin__search"
          :placeholder="t('admin.searchPlaceholder')"
          :debounce="300"
          data-cy="admin-search"
          @ion-input="reload()"
        />

        <div class="admin__filters">
          <div class="ic-chip-row" role="radiogroup">
            <ion-chip
              v-for="f in filters"
              :key="f"
              class="ic-chip"
              :class="{ 'ic-chip-selected': f === filter }"
              role="radio"
              :aria-checked="f === filter"
              @click="setFilter(f)"
            >
              {{ t(`admin.filters.${f}`) }}
            </ion-chip>
          </div>
          <ion-select
            v-model="sort"
            class="admin__sort"
            interface="popover"
            :aria-label="t('friends.sort.label')"
            @ion-change="reload()"
          >
            <ion-select-option value="createdAt">{{ t('admin.sort.createdAt') }}</ion-select-option>
            <ion-select-option value="displayName">{{ t('admin.sort.displayName') }}</ion-select-option>
          </ion-select>
        </div>

        <p class="ic-muted admin__total">{{ t('admin.total', { count: total }, total) }}</p>

        <div class="table" role="table" :aria-label="t('admin.title')">
          <div class="row row--head" role="row">
            <span role="columnheader" />
            <span role="columnheader">{{ t('admin.columns.user') }}</span>
            <span role="columnheader">{{ t('admin.columns.email') }}</span>
            <span role="columnheader">{{ t('admin.columns.signIn') }}</span>
            <span role="columnheader" class="num">{{ t('admin.columns.friends') }}</span>
            <span role="columnheader">{{ t('admin.columns.createdAt') }}</span>
          </div>

          <div
            v-for="u in users"
            :key="u.id"
            class="row"
            :class="{ 'row--selected': selected.has(u.id) }"
            role="row"
            tabindex="0"
            data-cy="admin-user"
            @click="openCard(u.id)"
            @keydown.enter="openCard(u.id)"
          >
            <span role="cell" class="cell--check" @click.stop>
              <ion-checkbox
                v-if="isConnectable(u)"
                :checked="selected.has(u.id)"
                :aria-label="u.displayName ?? u.email ?? ''"
                data-cy="admin-select"
                @ion-change="toggle(u)"
              />
            </span>
            <span role="cell" class="cell--user">
              <AppAvatar :id="u.id" :name="u.displayName" :url="u.avatarUrl" :size="36" />
              <span>
                <strong>{{ u.displayName ?? '—' }}</strong>
                <span class="tags">
                  <span class="tag" :class="{ 'tag--child': 'managed' === u.type }">{{ t(`admin.type.${u.type}`) }}</span>
                  <span v-if="u.isSuperAdmin" class="tag tag--admin">{{ t('admin.superAdmin') }}</span>
                </span>
                <span v-if="u.managers.length" class="ic-muted small">
                  {{ t('admin.managedBy', { names: joinNames(u.managers.map((m) => m.displayName), locale) }) }}
                </span>
              </span>
            </span>
            <span role="cell" class="cell--email">
              <span>{{ u.email ?? '—' }}</span>
              <span v-if="u.email && !u.emailVerified" class="warn small">{{ t('admin.unverified') }}</span>
              <span v-if="u.deletionScheduledAt" class="danger small">{{ t('admin.deleting') }}</span>
            </span>
            <span role="cell" class="cell--signin" data-cy="admin-signin">
              <span v-for="key in signInKeys(u)" :key="key" class="tag" :class="`tag--${key}`">{{ t(`admin.signIn.${key}`) }}</span>
              <span v-if="!signInKeys(u).length" class="ic-muted small">{{ t('admin.signIn.none') }}</span>
            </span>
            <span role="cell" class="num">{{ u.friendsCount }}<span class="label-narrow">{{ t('admin.columns.friends').toLowerCase() }}</span></span>
            <span role="cell" class="ic-muted small">{{ date(u.createdAt) }}</span>
          </div>

          <p v-if="!loading && !users.length" class="ic-muted empty">{{ t('admin.empty') }}</p>
        </div>

        <div v-if="pages > 1" class="pager">
          <ion-button fill="clear" :disabled="page <= 1" @click="goTo(page - 1)">{{ t('admin.previous') }}</ion-button>
          <span class="ic-muted">{{ t('admin.page', { page, pages }) }}</span>
          <ion-button fill="clear" :disabled="page >= pages" @click="goTo(page + 1)">{{ t('admin.next') }}</ion-button>
        </div>
      </div>

      <ion-modal :is-open="null !== cardId" class="admin-card-modal" @did-dismiss="closeCard">
        <AdminUserCard v-if="cardId" :user-id="cardId" @close="closeCard" @changed="changed = true" @open="(id: string) => (cardId = id)" />
      </ion-modal>
    </ion-content>

    <ion-footer v-if="selected.size" class="selection">
      <div class="selection__bar" data-cy="admin-selection">
        <span>{{ t('admin.selected', { count: selected.size }, selected.size) }}</span>
        <ion-button fill="clear" size="small" @click="selected = new Map()">{{ t('admin.clearSelection') }}</ion-button>
        <span class="spacer" />
        <ion-button class="ic-button-surface" :disabled="selected.size < 2 || busy" data-cy="admin-introduce" @click="connect('introduce')">
          {{ t('admin.introduceAll') }}
        </ion-button>
        <ion-button color="dark" :disabled="selected.size < 2 || busy" data-cy="admin-force" @click="connect('force')">
          {{ t('admin.forceAll') }}
        </ion-button>
      </div>
    </ion-footer>
  </ion-page>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  alertController,
  IonButton,
  IonCheckbox,
  IonChip,
  IonContent,
  IonFooter,
  IonModal,
  IonPage,
  IonSearchbar,
  IonSelect,
  IonSelectOption,
  onIonViewWillEnter,
  onIonViewWillLeave,
  toastController,
} from '@ionic/vue';
import AppAvatar from '../components/AppAvatar.vue';
import AdminUserCard from '../components/AdminUserCard.vue';
import TopBar from '../components/TopBar.vue';
import { adminApi } from '../services/admin';
import { useErrorMessage } from '../composables/useErrorMessage';
import { isConnectable, pairCount, signInKeys } from '../utils/admin';
import { joinNames } from '../utils/names';
import type { AdminMode, AdminUser, AdminUserFilter } from '../types/admin';

/**
 * The superadmin's back-office (spec §5.17, §11 décision 71): every
 * account, and friendships between them. Online only.
 */
const { t, locale } = useI18n();
const { describe } = useErrorMessage();

const filters: AdminUserFilter[] = ['all', 'regular', 'managed', 'deleting'];
const query = ref('');
const filter = ref<AdminUserFilter>('all');
const sort = ref<'createdAt' | 'displayName'>('createdAt');
const page = ref(1);
const total = ref(0);
const pageSize = ref(50);
const users = ref<AdminUser[]>([]);
const loading = ref(false);
const busy = ref(false);
/** Kept across pages and searches, to connect people found separately. */
const selected = ref(new Map<string, AdminUser>());
const cardId = ref<string | null>(null);
const changed = ref(false);

const pages = computed(() => Math.max(1, Math.ceil(total.value / pageSize.value)));

onIonViewWillEnter(() => {
  document.documentElement.dataset.layout = 'wide';
  void load();
});
onIonViewWillLeave(() => {
  delete document.documentElement.dataset.layout;
});

async function load(): Promise<void> {
  loading.value = true;
  try {
    const result = await adminApi.users({ q: query.value, type: filter.value, sort: sort.value, page: page.value });
    users.value = result.items;
    total.value = result.total;
    pageSize.value = result.pageSize;
  } catch (e) {
    await toast(describe(e), 'danger');
  } finally {
    loading.value = false;
  }
}

function reload(): Promise<void> {
  page.value = 1;

  return load();
}

function setFilter(f: AdminUserFilter): void {
  filter.value = f;
  void reload();
}

function goTo(p: number): void {
  page.value = p;
  void load();
}

function toggle(user: AdminUser): void {
  const next = new Map(selected.value);
  if (next.has(user.id)) next.delete(user.id);
  else next.set(user.id, user);
  selected.value = next;
}

function openCard(id: string): void {
  changed.value = false;
  cardId.value = id;
}

function closeCard(): void {
  cardId.value = null;
  if (changed.value) void load();
}

async function connect(mode: AdminMode): Promise<void> {
  const ids = [...selected.value.keys()];
  const alert = await alertController.create({
    header: t('admin.confirmTitle', { mode: t(`admin.modes.${mode}`), count: pairCount(ids.length) }, pairCount(ids.length)),
    message: t('introduce' === mode ? 'admin.introduceHint' : 'admin.forceHint'),
    buttons: [
      { text: t('admin.cancel'), role: 'cancel' },
      { text: t('admin.confirm'), role: 'confirm' },
    ],
  });
  await alert.present();
  if ('confirm' !== (await alert.onDidDismiss()).role) return;

  busy.value = true;
  try {
    const { done, unavailable } = await adminApi.connect(ids, mode);
    selected.value = new Map();
    await toast(t('admin.result', { done, unavailable }, done), 'success');
    await load();
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
.admin {
  padding: 0 16px 24px;
}

.admin__search {
  padding: 0;
  --background: var(--ic-surface);
  --border-radius: 12px;
}

.admin__filters {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  justify-content: space-between;
}

.admin__sort {
  min-height: 36px;
  font-size: 14px;
}

.admin__total {
  margin: 8px 0;
}

.table {
  border: 1px solid var(--ic-border);
  border-radius: 16px;
  background: var(--ic-surface);
  overflow: hidden;
}

.row {
  display: grid;
  grid-template-columns: 36px minmax(0, 1.6fr) minmax(0, 1.6fr) minmax(0, 1.3fr) 56px 92px;
  gap: 12px;
  align-items: center;
  padding: 10px 14px;
  border-bottom: 1px solid var(--ic-border);
  cursor: pointer;
}

.row:last-child {
  border-bottom: 0;
}

.row:hover,
.row:focus-visible {
  background: var(--ic-surface-muted);
  outline: none;
}

.row--selected {
  background: var(--ic-primary-soft);
}

.row--head {
  cursor: default;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--ic-text-secondary);
  background: var(--ic-surface-muted);
}

.row--head:hover {
  background: var(--ic-surface-muted);
}

.cell--user {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}

.cell--user > span,
.cell--email {
  display: flex;
  flex-direction: column;
  min-width: 0;
  overflow-wrap: anywhere;
}

.cell--signin,
.tags {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
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

.tag--child {
  background: var(--ic-success-soft);
  color: var(--ion-color-success);
}

.tag--admin,
.tag--emailLink {
  background: var(--ic-primary-soft);
  color: var(--ic-primary-text-on-soft);
}

.small {
  font-size: 12px;
}

.warn {
  color: var(--ion-color-warning-shade, #8a5a00);
}

.danger {
  color: var(--ion-color-danger);
}

.num {
  text-align: end;
}

.label-narrow {
  display: none;
}

.empty {
  padding: 16px;
  margin: 0;
}

.pager {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  margin-top: 12px;
}

.selection__bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  padding: 10px 16px calc(10px + var(--ion-safe-area-bottom, 0px));
  background: var(--ic-surface);
  border-top: 1px solid var(--ic-border);
}

.spacer {
  flex: 1;
}

/* Phone width: each row becomes a card, no horizontal scroll. */
@media (max-width: 719px) {
  .row--head {
    display: none;
  }

  .row {
    grid-template-columns: 32px minmax(0, 1fr) auto;
    grid-template-areas:
      'check user num'
      'check email email'
      'check signin date';
    row-gap: 6px;
  }

  .cell--check { grid-area: check; align-self: start; }
  .cell--user { grid-area: user; }
  .cell--email { grid-area: email; }
  .cell--signin { grid-area: signin; }
  .row > .num { grid-area: num; align-self: start; }
  .row > :last-child { grid-area: date; text-align: end; }

  .label-narrow {
    display: inline;
    margin-inline-start: 4px;
    font-size: 12px;
    color: var(--ic-text-secondary);
  }
}
</style>

<style>
@media (min-width: 720px) {
  ion-modal.admin-card-modal {
    --width: 640px;
    --height: 86%;
    --border-radius: 20px;
  }
}
</style>
