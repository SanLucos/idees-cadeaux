<template>
  <SecretZone :name="ownerName">
    <!-- Contribution in progress (or its history) -->
    <div v-if="contribution" class="ic-card block">
      <div class="block__head">
        <strong>{{ t('interactions.contributionBy', { name: contribution.isInitiator ? t('interactions.you') : contribution.initiator.displayName }) }}</strong>
        <span v-if="'closed' === contribution.status" class="ic-muted">{{ t('interactions.contributionClosed') }}</span>
        <span v-else-if="contribution.remainingAmount" class="ic-muted">
          {{ t('interactions.remaining', { amount: money(contribution.remainingAmount) }) }}
        </span>
      </div>
      <div class="amounts">
        <span class="amounts__total">{{ money(contribution.totalAmount) }}</span>
        <span v-if="contribution.targetAmount" class="ic-muted">{{ t('interactions.outOf', { amount: money(contribution.targetAmount) }) }}</span>
      </div>
      <ContributionProgress :total="contribution.totalAmount" :target="contribution.targetAmount" />
      <div v-if="contribution.participants.length" class="participants">
        <div class="avatars">
          <AppAvatar
            v-for="p in contribution.participants.slice(0, 4)"
            :id="p.user.id"
            :key="p.user.id"
            :name="p.user.displayName"
            :url="p.user.avatarUrl"
            :size="30"
            :primary="p.isMe"
          />
        </div>
        <span class="ic-muted">{{ participantNames }}</span>
      </div>
      <ion-button color="secret" expand="block" :router-link="`/contributions/${contribution.id}`">
        <ion-icon slot="start" :icon="cashOutline" />
        {{ t('interactions.seeContribution') }}
      </ion-button>
    </div>

    <!-- Reservation -->
    <div v-else-if="reservation" class="ic-card block">
      <div class="reserved">
        <ion-icon :icon="checkmarkCircle" color="success" aria-hidden="true" />
        <strong>{{ reservation.isMine ? t('interactions.youGiveIt') : t('interactions.reservedBy', { name: reservation.user.displayName }) }}</strong>
      </div>
      <div v-if="reservation.isMine && active" class="buttons">
        <ion-button class="ic-button-surface" expand="block" :disabled="busy" @click="run(() => interactionsApi.cancelReservation(reservation!.id))">
          {{ t('interactions.cancelReservation') }}
        </ion-button>
        <ion-button color="secret" fill="outline" expand="block" :disabled="busy" @click="convert">
          {{ t('interactions.openToOthers') }}
        </ion-button>
      </div>
    </div>

    <!-- Free: offer it alone or together (FicheIdee: "Idée non réservée") -->
    <div v-else-if="active" class="buttons">
      <ion-button expand="block" :disabled="busy" @click="run(() => interactionsApi.reserve(idea.id))">
        <ion-icon slot="start" :icon="giftOutline" />
        {{ t('interactions.reserve') }}
      </ion-button>
      <ion-button color="secret" fill="outline" expand="block" :disabled="busy" @click="openContribution">
        <ion-icon slot="start" :icon="peopleOutline" />
        {{ t('interactions.contributeTogether') }}
      </ion-button>
    </div>

    <div class="row">
      <ion-button
        v-if="idea.canReact"
        class="like"
        :class="{ 'like--on': idea.reactions?.likedByMe }"
        :aria-pressed="!!idea.reactions?.likedByMe"
        :disabled="busy"
        @click="toggleLike"
      >
        <ion-icon slot="start" :icon="idea.reactions?.likedByMe ? heart : heartOutline" />
        {{ t('interactions.like', { count: idea.reactions?.count ?? 0 }) }}
      </ion-button>
      <span v-else-if="idea.reactions?.count" class="ic-muted likes-count">
        <ion-icon :icon="heartOutline" aria-hidden="true" /> {{ idea.reactions.count }}
      </span>
      <ion-button v-if="idea.canMarkGifted" class="ic-button-surface" :disabled="busy" @click="markGifted">
        <ion-icon slot="start" :icon="archiveOutline" />
        {{ t('ideas.actions.markGifted') }}
      </ion-button>
    </div>

    <!-- Flat comment thread (spec §5.8) -->
    <div class="comments">
      <h3>{{ t('interactions.commentsTitle', { count: comments.length }) }}</h3>
      <div v-for="c in comments" :key="c.id" class="comment">
        <AppAvatar :id="c.author.id" :name="c.author.displayName" :url="c.author.avatarUrl" :size="36" :primary="c.isMine" />
        <div class="comment__bubble" @click="c.isMine && commentActions(c)">
          <div class="comment__head">
            <strong>{{ c.isMine ? t('interactions.you') : c.author.displayName }}</strong>
            <span class="ic-muted">
              {{ relativeTime(c.createdAt, locale) ?? t('interactions.justNow') }}<template v-if="c.editedAt"> · {{ t('interactions.edited') }}</template>
            </span>
          </div>
          <p>{{ c.body }}</p>
        </div>
      </div>
      <form v-if="active" class="comment-form" @submit.prevent="addComment">
        <ion-input
          v-model="draft"
          class="ic-field"
          fill="outline"
          :aria-label="t('interactions.addComment')"
          :placeholder="t('interactions.addComment')"
          :maxlength="1000"
        />
        <ion-button type="submit" color="secret" shape="round" :aria-label="t('interactions.send')" :disabled="!draft.trim() || busy">
          <ion-icon slot="icon-only" :icon="sendOutline" />
        </ion-button>
      </form>
    </div>
  </SecretZone>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  archiveOutline,
  cashOutline,
  checkmarkCircle,
  giftOutline,
  heart,
  heartOutline,
  peopleOutline,
  sendOutline,
} from 'ionicons/icons';
import { actionSheetController, alertController, IonButton, IonIcon, IonInput, toastController, useIonRouter } from '@ionic/vue';
import { ApiError } from '../services/api';
import { ideasApi } from '../services/ideas';
import { interactionsApi } from '../services/interactions';
import { useErrorMessage } from '../composables/useErrorMessage';
import { formatPrice } from '../utils/price';
import { relativeTime } from '../utils/relativeTime';
import AppAvatar from './AppAvatar.vue';
import ContributionProgress from './ContributionProgress.vue';
import SecretZone from './SecretZone.vue';
import type { Comment, Idea } from '../types/idea';

/**
 * The FicheIdee mock-up's "Entre amis" block. Its parent only mounts
 * it when the API sent hidden fields (friend view of a published idea):
 * everything below comes from the server's answer, nothing is masked
 * client-side (DESIGN.md § 3).
 */
const props = defineProps<{ idea: Idea; ownerName: string | null }>();
const emit = defineEmits<{ update: [idea: Idea] }>();

const { t, locale } = useI18n();
const ionRouter = useIonRouter();
const { describe } = useErrorMessage();

const comments = ref<Comment[]>([]);
const draft = ref('');
const busy = ref(false);

const active = computed(() => 'active' === props.idea.status);
const reservation = computed(() => props.idea.reservation ?? null);
const contribution = computed(() => props.idea.contribution ?? null);
const participantNames = computed(() =>
  (contribution.value?.participants ?? []).map((p) => (p.isMe ? t('interactions.you') : p.user.displayName)).join(', '),
);

const money = (amount: string | null) => formatPrice(amount, contribution.value?.currency ?? props.idea.priceCurrency, locale.value) ?? '';

async function loadComments(): Promise<void> {
  comments.value = await interactionsApi.comments(props.idea.id);
}

onMounted(loadComments);
watch(() => props.idea.commentCount, (count, previous) => {
  if (count !== previous) void loadComments();
});

async function toast(message: string): Promise<void> {
  await (await toastController.create({ message, duration: 3500, color: 'danger' })).present();
}

async function run(action: () => Promise<Idea>): Promise<void> {
  busy.value = true;
  try {
    emit('update', await action());
  } catch (e) {
    // e.g. « Déjà réservé par Hugo » when another sync won (spec §5.7).
    await toast(describe(e));
    emit('update', await ideasApi.get(props.idea.id));
  } finally {
    busy.value = false;
  }
}

function toggleLike(): Promise<void> {
  return run(() => (props.idea.reactions?.likedByMe ? interactionsApi.unlike(props.idea.id) : interactionsApi.like(props.idea.id)));
}

function markGifted(): Promise<void> {
  return run(() => ideasApi.archive(props.idea.id, 'gifted'));
}

async function openContribution(): Promise<void> {
  busy.value = true;
  try {
    const created = await interactionsApi.openContribution(props.idea.id);
    ionRouter.push(`/contributions/${created.id}?pledge=1`);
  } catch (e) {
    // Lost the race: join the existing contribution instead (spec §5.10).
    if (e instanceof ApiError && 'contribution.already_open' === e.code && e.extra.contributionId) {
      ionRouter.push(`/contributions/${String(e.extra.contributionId)}?pledge=1`);
    } else {
      await toast(describe(e));
    }
  } finally {
    busy.value = false;
  }
}

/** Spec §5.7: the reserver opens it to others and is offered to pledge right away. */
async function convert(): Promise<void> {
  const alert = await alertController.create({
    header: t('interactions.convertConfirm.title'),
    message: t('interactions.convertConfirm.message'),
    buttons: [
      { text: t('common.cancel'), role: 'cancel' },
      { text: t('interactions.openToOthers'), role: 'confirm' },
    ],
  });
  await alert.present();
  if ('confirm' !== (await alert.onDidDismiss()).role) return;

  busy.value = true;
  try {
    const created = await interactionsApi.convertToContribution(reservation.value!.id);
    ionRouter.push(`/contributions/${created.id}?pledge=1`);
  } catch (e) {
    await toast(describe(e));
  } finally {
    busy.value = false;
  }
}

async function addComment(): Promise<void> {
  const body = draft.value.trim();
  if (!body) return;
  busy.value = true;
  try {
    comments.value.push(await interactionsApi.addComment(props.idea.id, body));
    draft.value = '';
    emit('update', { ...props.idea, commentCount: comments.value.length });
  } catch (e) {
    await toast(describe(e));
  } finally {
    busy.value = false;
  }
}

async function commentActions(comment: Comment): Promise<void> {
  const sheet = await actionSheetController.create({
    buttons: [
      { text: t('ideas.actions.edit'), handler: () => void editComment(comment) },
      { text: t('common.delete'), role: 'destructive', handler: () => void deleteComment(comment) },
      { text: t('common.cancel'), role: 'cancel' },
    ],
  });
  await sheet.present();
}

async function editComment(comment: Comment): Promise<void> {
  const alert = await alertController.create({
    header: t('interactions.editComment'),
    inputs: [{ name: 'body', type: 'textarea', value: comment.body, attributes: { maxlength: 1000 } }],
    buttons: [
      { text: t('common.cancel'), role: 'cancel' },
      { text: t('common.save'), role: 'confirm' },
    ],
  });
  await alert.present();
  const result = await alert.onDidDismiss();
  if ('confirm' !== result.role) return;

  try {
    const updated = await interactionsApi.editComment(comment.id, String(result.data.values.body ?? ''));
    comments.value = comments.value.map((c) => (c.id === updated.id ? updated : c));
  } catch (e) {
    await toast(describe(e));
  }
}

async function deleteComment(comment: Comment): Promise<void> {
  try {
    await interactionsApi.deleteComment(comment.id);
    comments.value = comments.value.filter((c) => c.id !== comment.id);
    emit('update', { ...props.idea, commentCount: comments.value.length });
  } catch (e) {
    await toast(describe(e));
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

.block ion-button {
  margin: 0;
}

.block__head span {
  white-space: nowrap;
}

.block__head {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 8px;
  font-size: 15px;
}

.amounts {
  display: flex;
  align-items: baseline;
  gap: 8px;
}

.amounts__total {
  font-family: var(--ic-font-display);
  font-size: 36px;
  line-height: 1;
}

.participants {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 14px;
}

.avatars {
  display: flex;
}

.avatars > * + * {
  margin-left: -8px;
}

.reserved {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 16px;
}

.reserved ion-icon {
  font-size: 22px;
}

.buttons {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.buttons ion-button {
  margin: 0;
}

.row {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
}

.row ion-button {
  margin: 0;
}

.like {
  --background: var(--ic-surface);
  --color: var(--ion-color-secret);
  --border-color: var(--ic-secret-border);
  --border-style: solid;
  --border-width: 1px;
  --border-radius: 999px;
}

.like--on {
  --background: var(--ic-secret-soft);
}

.likes-count {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.comments h3 {
  margin: 4px 0 10px;
  font-family: var(--ic-font-body);
  font-size: 16px;
  font-weight: 700;
}

.comment {
  display: flex;
  gap: 10px;
  margin-bottom: 10px;
}

.comment__bubble {
  flex-grow: 1;
  min-width: 0;
  padding: 10px 12px;
  border: 1px solid var(--ic-border);
  border-radius: var(--ic-radius-card);
  background: var(--ic-surface);
}

.comment__head {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  font-size: 14px;
}

.comment__bubble p {
  margin: 4px 0 0;
  font-size: 15px;
  line-height: 1.4;
  white-space: pre-line;
  overflow-wrap: anywhere;
}

.comment-form {
  display: flex;
  gap: 10px;
  align-items: center;
}

.comment-form ion-input {
  flex-grow: 1;
}

.comment-form ion-button {
  --border-radius: 50%;
  width: 48px;
  height: 48px;
  margin: 0;
}
</style>
