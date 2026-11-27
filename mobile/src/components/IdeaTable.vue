<template>
  <div class="idea-table">
    <table>
      <thead>
        <tr>
          <th v-for="column in shownColumns" :key="column" scope="col" :class="`col-${column}`" :aria-sort="ariaSort(column)">
            <button v-if="isSortable(column)" type="button" class="sort-button" :class="{ 'sort-button--active': sort.key === column }" @click="sortBy(column)">
              {{ t(`desktop.columns.${column}`) }}
              <ion-icon v-if="sort.key === column" :icon="'asc' === sort.dir ? arrowUp : arrowDown" aria-hidden="true" />
            </button>
            <template v-else>{{ t(`desktop.columns.${column}`) }}</template>
          </th>
          <th v-if="hasActions" scope="col" class="col-actions"><span class="visually-hidden">{{ t('desktop.columns.actions') }}</span></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="idea in ideas" :key="idea.id" :data-idea="idea.id">
          <td v-for="column in shownColumns" :key="column" :class="`col-${column}`">
            <router-link v-if="'idea' === column" :to="`/ideas/${idea.id}`" class="idea-link">
              <IdeaThumb :id="idea.id" :url="idea.thumbnailUrl" :size="48" />
              <span>{{ idea.title }}</span>
            </router-link>

            <template v-else-if="'state' === column">
              <span v-if="'published' === ideaState(idea)" class="state-published">
                <span class="dot" aria-hidden="true" />{{ t('desktop.state.published') }}
              </span>
              <StatusPill v-else-if="'draft' === ideaState(idea)" variant="draft" :icon="lockClosedOutline">{{ t('desktop.state.draft') }}</StatusPill>
              <StatusPill v-else :icon="archiveOutline">{{ t('desktop.state.archived') }}</StatusPill>
            </template>

            <template v-else-if="'source' === column">
              <StatusPill v-if="'suggestion' === ideaSource(idea)" variant="suggestion" :icon="eyeOffOutline">{{ sourceLabel(idea) }}</StatusPill>
              <StatusPill v-else-if="'draft' === ideaSource(idea)" variant="draft" :icon="lockClosedOutline">{{ sourceLabel(idea) }}</StatusPill>
              <span v-else>{{ sourceLabel(idea) }}</span>
            </template>

            <span v-else-if="'occasion' === column" :class="{ 'ic-muted': !idea.occasion }">{{ occasionLabel(idea) }}</span>

            <template v-else-if="'price' === column">{{ formatPrice(idea.priceAmount, idea.priceCurrency, locale) }}</template>

            <template v-else-if="'status' === column">
              <StatusPill v-if="'archived' === idea.status" :icon="archiveOutline">{{ archivedLabel(idea) }}</StatusPill>
              <ion-button v-else-if="'private' === idea.visibility && idea.canEdit" class="ic-button-surface publish" size="small" @click="emit('publish', idea)">
                {{ t('ideas.actions.publish') }}
              </ion-button>
              <InteractionPills v-else-if="isTaken(idea)" :idea="idea" only="status" />
              <span v-else-if="'reservation' in idea" class="ic-muted">{{ t('desktop.status.free') }}</span>
            </template>

            <span v-else-if="'interactions' === column" class="counters"><InteractionPills :idea="idea" only="counters" /></span>

            <span v-else-if="'added' === column" class="ic-muted">{{ formatDate(idea.createdAt) }}</span>
          </td>
          <td v-if="hasActions" class="col-actions">
            <ion-button
              v-if="actions(idea)"
              fill="clear"
              color="medium"
              class="more"
              :aria-label="t('common.actionsFor', { name: idea.title })"
              @click="emit('actions', idea)"
            >
              <ion-icon aria-hidden="true" slot="icon-only" :icon="ellipsisHorizontal" />
            </ion-button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { IonButton, IonIcon } from '@ionic/vue';
import { archiveOutline, arrowDown, arrowUp, ellipsisHorizontal, eyeOffOutline, lockClosedOutline } from 'ionicons/icons';
import IdeaThumb from './IdeaThumb.vue';
import InteractionPills from './InteractionPills.vue';
import StatusPill from './StatusPill.vue';
import { formatPrice } from '../utils/price';
import { useIdeaLabels } from '../composables/useIdeaLabels';
import { ideaSource, ideaState, type SortKey, type TableSort } from '../utils/ideaTable';
import type { Idea } from '../types/idea';

export type IdeaColumn = 'idea' | 'state' | 'source' | 'occasion' | 'price' | 'status' | 'interactions' | 'added';

/**
 * The ideas of a list as a table (spec §11 décision 72, computer layout).
 * Like IdeaCard, it only shows what each idea payload carries: the owner
 * view has no hidden field, so the « Statut » and « Entre amis » columns
 * stay empty there (DESIGN.md § 3) — they are only asked for on a friend's
 * list anyway.
 */
const props = withDefaults(
  defineProps<{
    ideas: Idea[];
    columns: IdeaColumn[];
    sort: TableSort;
    /** « Idée de X » in the Source column. */
    ownerName?: string | null;
    actions?: (idea: Idea) => boolean;
  }>(),
  { ownerName: null, actions: undefined },
);
const emit = defineEmits<{ 'update:sort': [sort: TableSort]; actions: [idea: Idea]; publish: [idea: Idea] }>();

const { t, locale } = useI18n();

const SORTABLE: readonly SortKey[] = ['title', 'state', 'source', 'occasion', 'price', 'added'];
const sortKey = (column: IdeaColumn): SortKey | null => {
  const key = 'idea' === column ? 'title' : column;

  return (SORTABLE as readonly string[]).includes(key) ? (key as SortKey) : null;
};

const shownColumns = computed(() => props.columns);
const hasActions = computed(() => !!props.actions);
const isSortable = (column: IdeaColumn) => null !== sortKey(column);

function sortBy(column: IdeaColumn): void {
  const key = sortKey(column)!;
  const dir = props.sort.key === key ? ('asc' === props.sort.dir ? 'desc' : 'asc') : 'added' === key ? 'desc' : 'asc';
  emit('update:sort', { key, dir });
}

function ariaSort(column: IdeaColumn): 'ascending' | 'descending' | undefined {
  if (sortKey(column) !== props.sort.key) return undefined;

  return 'asc' === props.sort.dir ? 'ascending' : 'descending';
}

const labels = useIdeaLabels();
const occasionLabel = labels.occasionLabel;
const sourceLabel = (idea: Idea) => labels.sourceLabel(idea, props.ownerName);

const isTaken = (idea: Idea) => !!idea.reservation || 'open' === idea.contribution?.status;

function formatDate(iso: string | null): string {
  if (!iso) return '';
  const date = new Date(iso);
  const sameYear = date.getFullYear() === new Date().getFullYear();

  return new Intl.DateTimeFormat(locale.value, { day: 'numeric', month: 'short', year: sameYear ? undefined : 'numeric' }).format(date);
}

function archivedLabel(idea: Idea): string {
  const kind = idea.archiveKind ? t(`ideas.archived.${idea.archiveKind}`) : t('desktop.state.archived');

  return idea.archivedAt ? t('desktop.status.archivedOn', { kind, date: formatDate(idea.archivedAt) }) : kind;
}
</script>

<style scoped>
.idea-table {
  overflow-x: auto;
  border: 1px solid var(--ic-border);
  border-radius: var(--ic-radius-card);
  background: var(--ic-surface);
}

table {
  width: 100%;
  min-width: 720px;
  border-collapse: collapse;
  font-size: 0.9375rem;
}

th {
  height: 44px;
  padding: 0 8px;
  border-bottom: 1px solid var(--ic-border);
  text-align: left;
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--ic-text-secondary);
  white-space: nowrap;
}

td {
  padding: 10px 8px;
  border-top: 1px solid var(--ic-border);
  vertical-align: middle;
}

tbody tr:first-child td {
  border-top: 0;
}

th:first-child,
td:first-child {
  padding-left: 16px;
}

.sort-button {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  min-height: 44px;
  padding: 0;
  border: 0;
  background: transparent;
  font: inherit;
  color: inherit;
  letter-spacing: inherit;
  text-transform: inherit;
  cursor: pointer;
}

.sort-button--active {
  color: var(--ion-text-color);
}

.idea-link {
  display: flex;
  align-items: center;
  gap: 14px;
  min-height: 48px;
  color: var(--ion-text-color);
  text-decoration: none;
  font-weight: 600;
}

.idea-link:hover span {
  text-decoration: underline;
}

.col-price {
  text-align: right;
  white-space: nowrap;
  font-weight: 600;
}

th.col-price .sort-button {
  flex-direction: row-reverse;
}

.col-state,
.col-source,
.col-occasion,
.col-status,
.col-added {
  white-space: nowrap;
}

.col-added {
  padding-left: 24px;
}

.col-actions {
  width: 56px;
  padding: 4px 6px;
}

.state-published {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.dot {
  width: 8px;
  height: 8px;
  border-radius: 4px;
  background: var(--ion-color-success);
}

.counters {
  display: inline-flex;
  gap: 12px;
}

.publish {
  margin: 0;
}

.more {
  width: 44px;
  height: 44px;
  margin: 0;
}

.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
  white-space: nowrap;
}
</style>
