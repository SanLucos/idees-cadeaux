<template>
  <ion-page>
    <ion-content>
      <TopBar :default-href="cancelHref" close-icon>{{ screenTitle }}</TopBar>

      <form class="form" novalidate @submit.prevent="submit">
        <!-- Recipient: only when creating; an idea never changes owner. -->
        <fieldset v-if="!isEdit && !activeProfile.isActing" class="block">
          <legend class="label">{{ t('ideaForm.forWhom') }}</legend>
          <div class="recipients" role="radiogroup">
            <button
              v-for="r in recipients"
              :key="r.id"
              type="button"
              role="radio"
              class="recipient"
              :class="{ 'recipient--selected': r.id === ownerId }"
              :aria-checked="r.id === ownerId"
              @click="ownerId = r.id"
            >
              <AppAvatar :id="r.id" :name="r.name" :url="r.avatarUrl" :size="40" :primary="r.isMe" />
              <span>{{ r.isMe ? t('ideaForm.me') : r.name }}</span>
            </button>
          </div>
        </fieldset>

        <SecretBand v-if="isSuggestion">{{ t('ideaForm.suggestionBand', { name: recipientName }) }}</SecretBand>

        <div class="link-row">
          <ion-input
            v-model="url"
            class="ic-field"
            fill="outline"
            type="url"
            inputmode="url"
            :label="t('ideaForm.link')"
            label-placement="stacked"
            placeholder="https://"
          />
          <ion-button class="prefill" :disabled="!url.trim() || 'loading' === prefillState" @click="prefill">
            <ion-spinner v-if="'loading' === prefillState" slot="start" name="crescent" />
            <ion-icon v-else slot="start" :icon="sparklesOutline" aria-hidden="true" />
            {{ t('ideaForm.prefill.action') }}
          </ion-button>
        </div>

        <div v-if="prefillMessage" class="prefill-status" :class="`prefill-status--${prefillState}`" role="status">
          <ion-icon :icon="'found' === prefillState ? checkmarkOutline : 'deferred' === prefillState ? cloudOfflineOutline : informationCircleOutline" aria-hidden="true" />
          <div>
            <strong>{{ prefillMessage.title }}</strong>
            <div>{{ prefillMessage.detail }}</div>
          </div>
        </div>

        <ion-input
          v-model="title"
          class="ic-field"
          fill="outline"
          :label="t('ideaForm.title')"
          label-placement="stacked"
          :maxlength="120"
          :counter="true"
          required
        />

        <div class="price-row">
          <ion-input
            v-model="priceAmount"
            class="ic-field"
            fill="outline"
            inputmode="decimal"
            :label="t('ideaForm.price')"
            label-placement="stacked"
          />
          <ion-select v-model="priceCurrency" class="ic-field currency" fill="outline" :label="t('ideaForm.currency')" label-placement="stacked" interface="popover">
            <ion-select-option v-for="c in CURRENCIES" :key="c" :value="c">{{ c }}</ion-select-option>
          </ion-select>
        </div>

        <div class="block">
          <div class="label">{{ t('ideaForm.image') }}</div>
          <div class="image-row">
            <div class="image-preview" :style="thumbStyle">
              <img v-if="imagePreview" :src="imagePreview" alt="" />
              <ion-icon v-else :icon="imageOutline" aria-hidden="true" />
            </div>
            <div class="image-actions">
              <div v-if="imageFromLink" class="image-source">{{ t('ideaForm.prefill.imageFromLink') }}</div>
              <div class="image-buttons">
                <input ref="fileInput" type="file" accept="image/png,image/jpeg,image/webp" hidden @change="onImagePicked" />
                <ion-button class="ic-button-surface" @click="fileInput?.click()">
                  <ion-icon slot="start" :icon="imageOutline" />
                  {{ imagePreview ? t('ideaForm.changeImage') : t('ideaForm.addImage') }}
                </ion-button>
                <ion-button v-if="imagePreview" class="ic-button-surface" :aria-label="t('ideaForm.removeImage')" @click="clearImage">
                  <ion-icon slot="icon-only" :icon="trashOutline" />
                </ion-button>
              </div>
            </div>
          </div>
        </div>

        <ion-textarea
          v-model="note"
          class="ic-field"
          fill="outline"
          :label="t('ideaForm.note')"
          label-placement="stacked"
          :placeholder="t('ideaForm.notePlaceholder')"
          :maxlength="2000"
          :counter="true"
          :auto-grow="true"
          :rows="3"
        />

        <div class="block">
          <div class="label">{{ t('ideaForm.occasion') }}</div>
          <div class="occasions">
            <ion-chip class="ic-chip" :class="{ 'ic-chip-selected': null === occasion }" @click="occasion = null">
              {{ t('ideaForm.noOccasion') }}
            </ion-chip>
            <ion-chip
              v-for="o in visibleOccasions"
              :key="o.code"
              class="ic-chip"
              :class="{ 'ic-chip-selected': o.code === occasion }"
              @click="occasion = o.code"
            >
              {{ t(`occasions.${o.code}`) }}
            </ion-chip>
          </div>
          <ion-button v-if="!showAllOccasions" fill="clear" size="small" class="see-all" @click="showAllOccasions = true">
            {{ t('ideaForm.seeAllOccasions', { count: occasionsStore.occasions.length }) }}
          </ion-button>
        </div>

        <fieldset class="block">
          <legend class="label">{{ t('ideaForm.visibility') }}</legend>
          <ion-radio-group v-model="visibility" class="visibility">
            <ion-item class="visibility-option" :class="{ 'visibility-option--selected': 'published' === visibility }" lines="none">
              <ion-radio slot="start" value="published" label-placement="end" justify="start">
                <div class="visibility-option__title"><ion-icon :icon="peopleOutline" aria-hidden="true" />{{ t('ideaForm.published') }}</div>
                <div class="visibility-option__hint">{{ publishedHint }}</div>
              </ion-radio>
            </ion-item>
            <ion-item class="visibility-option" :class="{ 'visibility-option--selected': 'private' === visibility }" lines="none">
              <ion-radio slot="start" value="private" label-placement="end" justify="start">
                <div class="visibility-option__title"><ion-icon :icon="lockClosedOutline" aria-hidden="true" />{{ t('ideaForm.private') }}</div>
                <div class="visibility-option__hint">{{ t('ideaForm.privateHint') }}</div>
              </ion-radio>
            </ion-item>
          </ion-radio-group>
        </fieldset>

        <ion-text v-if="error" color="danger"><p>{{ error }}</p></ion-text>

        <ion-button expand="block" type="submit" :disabled="saving || !title.trim()">{{ submitLabel }}</ion-button>
      </form>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import {
  checkmarkOutline,
  cloudOfflineOutline,
  imageOutline,
  informationCircleOutline,
  lockClosedOutline,
  peopleOutline,
  sparklesOutline,
  trashOutline,
} from 'ionicons/icons';
import {
  alertController,
  IonButton,
  IonChip,
  IonContent,
  IonIcon,
  IonInput,
  IonItem,
  IonPage,
  IonRadio,
  IonRadioGroup,
  IonSelect,
  IonSelectOption,
  IonSpinner,
  IonText,
  IonTextarea,
  useIonRouter,
} from '@ionic/vue';
import { ideasApi } from '../services/ideas';
import { ApiError, isNetworkError } from '../services/api';
import { linkPreviewApi, previewImageFile, type LinkPreview } from '../services/linkPreview';
import { addPendingPrefill } from '../offline/pendingPrefill';
import { useSync } from '../offline/sync';
import { useSharedContentStore } from '../stores/sharedContent';
import { fallbackTitle } from '../utils/sharedContent';
import { useAuthStore } from '../stores/auth';
import { useFriendsStore } from '../stores/friends';
import { useActiveProfileStore } from '../stores/activeProfile';
import { useOccasionsStore } from '../stores/occasions';
import { useErrorMessage } from '../composables/useErrorMessage';
import { colorIndex } from '../utils/colorIndex';
import { CURRENCIES } from '../utils/price';
import AppAvatar from '../components/AppAvatar.vue';
import SecretBand from '../components/SecretBand.vue';
import TopBar from '../components/TopBar.vue';
import type { Idea, IdeaInput, IdeaVisibility } from '../types/idea';

const OCCASIONS_SHOWN_BY_DEFAULT = 4;

const { t, locale } = useI18n();
const route = useRoute();
const ionRouter = useIonRouter();
const auth = useAuthStore();
const friendsStore = useFriendsStore();
const occasionsStore = useOccasionsStore();
const activeProfile = useActiveProfileStore();
const { describe } = useErrorMessage();

const editId = route.params.id ? String(route.params.id) : null;
const isEdit = null !== editId;
const existing = ref<Idea | null>(null);

/** "Moi": the active child when managing one (its own ideas only, spec §11 décision 25). */
const selfId = computed(() => activeProfile.activeId ?? auth.user?.id ?? '');
const ownerId = ref(String(activeProfile.activeId ?? route.query.ownerId ?? auth.user?.id ?? ''));
const url = ref('');
const title = ref('');
const priceAmount = ref('');
const priceCurrency = ref('EUR');
const note = ref('');
const occasion = ref<string | null>(null);
const visibility = ref<IdeaVisibility>('published');
const showAllOccasions = ref(false);
const saving = ref(false);
const error = ref('');

const fileInput = ref<HTMLInputElement>();
const pickedImage = ref<File | null>(null);
const imagePreview = ref<string | null>(null);
const removeExistingImage = ref(false);
const imageFromLink = ref(false);

/** Pre-fill from the link (spec §5.5) — or, offline, once the network is back (spec §5.6). */
type PrefillState = 'idle' | 'loading' | 'found' | 'empty' | 'unavailable' | 'deferred';
const prefillState = ref<PrefillState>('idle');
const prefillFound = ref<string[]>([]);
/** Set while offline: the idea is saved now and completed later (offline/pendingPrefill.ts). */
const deferredPrefill = ref<{ url: string; fallbackTitle: string } | null>(null);
const sync = useSync();
const sharedContent = useSharedContentStore();

const recipients = computed(() => [
  { id: auth.user?.id ?? '', name: auth.user?.displayName ?? '', avatarUrl: auth.user?.avatarUrl ?? null, isMe: true },
  ...friendsStore.friends.map((f) => ({ id: f.user.id, name: f.user.displayName, avatarUrl: f.user.avatarUrl, isMe: false })),
  // A manager may suggest to their children in their own name (spec §5.15).
  ...activeProfile.children
    .filter((c) => !c.deletionScheduledAt)
    .map((c) => ({ id: c.id, name: c.displayName, avatarUrl: c.avatarUrl, isMe: false })),
]);

const isSuggestion = computed(() => (isEdit ? !!existing.value?.isSuggestion && 'owner' !== existing.value?.view : ownerId.value !== selfId.value));
const recipientName = computed(() => {
  const id = isEdit ? existing.value?.ownerId : ownerId.value;

  return recipients.value.find((r) => r.id === id)?.name ?? '';
});

const screenTitle = computed(() => {
  if (isEdit) return t('ideaForm.editTitle');

  return isSuggestion.value ? t('ideaForm.newSuggestion') : t('ideas.new');
});

const publishedHint = computed(() =>
  isSuggestion.value ? t('ideaForm.publishedHintSuggestion', { name: recipientName.value }) : t('ideaForm.publishedHintOwn'),
);

const submitLabel = computed(() => {
  if (isEdit) return t('common.save');
  if ('private' === visibility.value) return t('ideaForm.saveDraft');

  return isSuggestion.value ? t('ideaForm.publishSuggestion') : t('ideaForm.publishIdea');
});

const cancelHref = computed(() => {
  if (isEdit) return `/ideas/${editId}`;

  return isSuggestion.value ? `/tabs/friends/${ownerId.value}` : '/tabs/list';
});

const visibleOccasions = computed(() => {
  const all = occasionsStore.occasions;
  if (showAllOccasions.value) return all;
  const shown = all.slice(0, OCCASIONS_SHOWN_BY_DEFAULT);
  // Keep the current choice visible even when it's beyond the first few.
  const selected = all.find((o) => o.code === occasion.value);

  return selected && !shown.includes(selected) ? [...shown, selected] : shown;
});

const thumbStyle = computed(() => {
  const index = colorIndex(editId ?? ownerId.value, 5);

  return { background: `var(--ic-thumb-bg-${index})`, color: `var(--ic-thumb-fg-${index})` };
});

const prefillMessage = computed(() => {
  switch (prefillState.value) {
    case 'found':
      return { title: t('ideaForm.prefill.found'), detail: t('ideaForm.prefill.foundDetail', { fields: new Intl.ListFormat(locale.value, { type: 'conjunction' }).format(prefillFound.value) }) };
    case 'empty':
      return { title: t('ideaForm.prefill.empty'), detail: t('ideaForm.prefill.manual') };
    case 'unavailable':
      return { title: t('ideaForm.prefill.unavailable'), detail: t('ideaForm.prefill.manual') };
    case 'deferred':
      return { title: t('ideaForm.prefill.deferred'), detail: t('ideaForm.prefill.deferredDetail') };
    default:
      return null;
  }
});

// A preview (or its deferral) belongs to the link it was made for.
const prefillUrl = ref('');
watch(url, (value) => {
  if (value.trim() === prefillUrl.value) return;
  deferredPrefill.value = null;
  prefillState.value = 'idle';
});

function deferPrefill(link: string): void {
  prefillUrl.value = link;
  const standIn = fallbackTitle(link);
  deferredPrefill.value = { url: link, fallbackTitle: standIn };
  if (!title.value.trim()) title.value = standIn;
  prefillState.value = 'deferred';
}

async function prefill(): Promise<void> {
  const link = url.value.trim();
  if (!link) return;
  prefillUrl.value = link;
  if (!sync.online) {
    deferPrefill(link);

    return;
  }

  prefillState.value = 'loading';
  error.value = '';
  try {
    applyPreview(await linkPreviewApi.fetch(link));
  } catch (e) {
    if (isNetworkError(e)) {
      deferPrefill(link);
    } else if (e instanceof ApiError && e.code.startsWith('link_preview.')) {
      prefillState.value = 'unavailable';
    } else {
      prefillState.value = 'idle';
      error.value = describe(e);
    }
  }
}

/** Proposed, never imposed (spec §5.5): only fills what's still empty. */
async function applyPreview(preview: LinkPreview): Promise<void> {
  const found: string[] = [];
  const titleIsStandIn = deferredPrefill.value && title.value === deferredPrefill.value.fallbackTitle;
  if (preview.title && (!title.value.trim() || titleIsStandIn)) {
    title.value = preview.title;
    found.push(t('ideaForm.prefill.fields.title'));
  }
  if (preview.imageDataUrl && !imagePreview.value) {
    pickedImage.value = await previewImageFile(preview.imageDataUrl);
    imagePreview.value = preview.imageDataUrl;
    imageFromLink.value = true;
    removeExistingImage.value = false;
    found.push(t('ideaForm.prefill.fields.image'));
  }
  if (preview.priceAmount && !priceAmount.value.trim()) {
    priceAmount.value = preview.priceAmount.replace(/\.00$/, '');
    priceCurrency.value = preview.priceCurrency ?? priceCurrency.value;
    found.push(t('ideaForm.prefill.fields.price'));
  }
  deferredPrefill.value = null;
  prefillFound.value = found;
  prefillState.value = found.length ? 'found' : 'empty';
}

/** Spec §5.6: a share from another app opens this form pre-filled. */
function receiveShare(): void {
  const shared = sharedContent.consume();
  if (!shared) return;
  url.value = shared.url ?? '';
  title.value = shared.title ?? '';
  if (!shared.url) return;
  if (sync.online) {
    void prefill();
  } else {
    deferPrefill(shared.url);
    // Saved as a private draft: its details arrive later, to be checked before friends see it.
    visibility.value = 'private';
  }
}

// Another share while this form is already open.
watch(
  () => sharedContent.pending,
  (pending) => {
    if (pending && !isEdit) receiveShare();
  },
);

onMounted(async () => {
  if (!isEdit && route.query.shared) receiveShare();
  await Promise.all([occasionsStore.ensureLoaded(), friendsStore.friends.length ? null : friendsStore.fetchFriends()]);
  if (!isEdit) return;

  const idea = await ideasApi.get(editId);
  existing.value = idea;
  url.value = idea.url ?? '';
  title.value = idea.title;
  priceAmount.value = idea.priceAmount ? idea.priceAmount.replace(/\.00$/, '') : '';
  priceCurrency.value = idea.priceCurrency;
  note.value = idea.note ?? '';
  occasion.value = idea.occasion;
  visibility.value = idea.visibility;
  imagePreview.value = idea.thumbnailUrl;
});

function onImagePicked(event: Event): void {
  const file = (event.target as HTMLInputElement).files?.[0];
  if (!file) return;
  pickedImage.value = file;
  imagePreview.value = URL.createObjectURL(file);
  removeExistingImage.value = false;
  imageFromLink.value = false;
}

function clearImage(): void {
  pickedImage.value = null;
  imagePreview.value = null;
  imageFromLink.value = false;
  removeExistingImage.value = !!existing.value?.imageUrl;
}

function fields(): IdeaInput {
  return {
    title: title.value.trim(),
    url: url.value.trim() || null,
    priceAmount: priceAmount.value.trim() || null,
    priceCurrency: priceCurrency.value,
    note: note.value.trim() || null,
    occasion: occasion.value,
  };
}

/** Spec §5.4: unpublishing warns first — generically for an owner (règle d'or). */
async function confirmUnpublish(idea: Idea): Promise<boolean> {
  const alert = await alertController.create({
    header: t('ideas.unpublishConfirm.title'),
    message: 'owner' === idea.view ? t('ideas.unpublishConfirm.owner') : t('ideas.unpublishConfirm.authorNothing'),
    buttons: [
      { text: t('common.cancel'), role: 'cancel' },
      { text: t('ideas.actions.unpublish'), role: 'confirm' },
    ],
  });
  await alert.present();

  return 'confirm' === (await alert.onDidDismiss()).role;
}

async function submit(): Promise<void> {
  saving.value = true;
  error.value = '';
  try {
    let idea: Idea;
    if (isEdit && existing.value) {
      if ('private' === visibility.value && 'published' === existing.value.visibility && !(await confirmUnpublish(existing.value))) {
        return;
      }
      idea = await ideasApi.update(existing.value.id, fields());
      if (visibility.value !== idea.visibility) {
        idea = 'published' === visibility.value ? await ideasApi.publish(idea.id) : await ideasApi.unpublish(idea.id);
      }
    } else {
      idea = await ideasApi.create({
        ...fields(),
        ownerId: ownerId.value,
        visibility: visibility.value,
      });
    }

    if (pickedImage.value) {
      idea = await ideasApi.uploadImage(idea.id, pickedImage.value);
    } else if (removeExistingImage.value) {
      idea = await ideasApi.removeImage(idea.id);
    }

    if (deferredPrefill.value && deferredPrefill.value.url === (idea.url ?? '')) {
      await addPendingPrefill({ ideaId: idea.id, ...deferredPrefill.value, actingAs: activeProfile.activeId });
    }

    ionRouter.navigate(`/ideas/${idea.id}`, isEdit ? 'back' : 'forward', 'replace');
  } catch (e) {
    error.value = describe(e);
  } finally {
    saving.value = false;
  }
}
</script>

<style scoped>
.form {
  display: flex;
  flex-direction: column;
  gap: 18px;
  padding-bottom: 32px;
}

fieldset {
  border: 0;
  margin: 0;
  padding: 0;
  min-width: 0;
}

.label {
  margin-bottom: 8px;
  padding: 0;
  font-size: 15px;
  font-weight: 700;
}

.recipients {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  margin: 0 calc(-1 * var(--ic-gutter));
  padding: 2px var(--ic-gutter);
  scrollbar-width: none;
}

.recipient {
  flex: 0 0 76px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  padding: 10px 4px;
  border: 1px solid var(--ic-border);
  border-radius: var(--ic-radius-card);
  background: var(--ic-surface);
  color: var(--ion-text-color);
  font: 600 13px var(--ic-font-body);
}

.recipient span {
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.recipient--selected {
  border: 2px solid var(--ion-color-primary);
  background: var(--ic-primary-soft);
}

.link-row {
  display: grid;
  grid-template-columns: 1fr auto;
  align-items: end;
  gap: 10px;
}

.prefill {
  --background: var(--ion-text-color);
  --color: var(--ic-surface);
  height: 56px;
  margin: 0;
}

.prefill ion-spinner {
  width: 18px;
  height: 18px;
  margin-inline-end: 6px;
}

.prefill-status {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  margin-top: -6px;
  padding: 12px 14px;
  border-radius: var(--ic-radius-card);
  background: var(--ic-surface-muted);
  color: var(--ion-text-color);
  font-size: 14px;
  line-height: 1.4;
}

.prefill-status--found {
  background: var(--ic-success-soft);
  color: var(--ion-color-success);
}

.prefill-status ion-icon {
  flex-shrink: 0;
  margin-top: 2px;
  font-size: 18px;
}

.image-actions {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.image-buttons {
  display: flex;
  gap: 10px;
}

.image-source {
  font-size: 13px;
  color: var(--ic-text-secondary);
}

.price-row {
  display: grid;
  grid-template-columns: 1fr 110px;
  gap: 10px;
}

.image-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.image-buttons ion-button {
  margin: 0;
}

.image-preview {
  width: 72px;
  height: 72px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: var(--ic-radius-field);
  overflow: hidden;
  font-size: 26px;
}

.image-preview img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.occasions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.see-all {
  margin: 6px 0 0 -8px;
  font-weight: 700;
}

.visibility {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.visibility-option {
  --background: var(--ic-surface);
  --padding-start: 14px;
  border: 1px solid var(--ic-border);
  border-radius: var(--ic-radius-card);
}

.visibility-option--selected {
  border: 2px solid var(--ion-text-color);
}

.visibility-option ion-radio {
  --color-checked: var(--ion-text-color);
  padding: 12px 0;
}

.visibility-option__title {
  display: flex;
  align-items: center;
  gap: 6px;
  font-weight: 700;
}

.visibility-option__hint {
  margin-top: 2px;
  font-size: 14px;
  color: var(--ic-text-secondary);
  white-space: normal;
}
</style>
