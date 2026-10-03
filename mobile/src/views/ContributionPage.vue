<template>
  <ion-page>
    <ion-content>
      <TopBar :default-href="contribution ? `/ideas/${contribution.ideaId}` : '/tabs/friends'">
        <div>{{ t('contribution.title') }}</div>
        <div v-if="contribution?.idea" class="subtitle">
          {{ t('contribution.subtitle', { title: contribution.idea.title, name: ownerName }) }}
        </div>
        <template v-if="contribution?.isInitiator && isOpen" #end>
          <ion-button class="ic-round-button" :aria-label="t('common.moreActions')" @click="initiatorMenu">
            <ion-icon aria-hidden="true" slot="icon-only" :icon="ellipsisHorizontal" />
          </ion-button>
        </template>
      </TopBar>

      <EmptyState v-if="notFound" :icon="helpCircleOutline">{{ t('contribution.notFound') }}</EmptyState>

      <template v-if="contribution">
        <SecretBand>{{ t('contribution.secretBand', { name: ownerName }) }}</SecretBand>

        <div v-if="!isOpen" class="closed-note" role="status">
          <ion-icon :icon="lockClosedOutline" aria-hidden="true" />
          {{ t('contribution.closedNote') }}
        </div>

        <div class="ic-card total">
          <div class="total__label">{{ t('contribution.totalDeclared') }}</div>
          <div class="total__amounts">
            <span class="total__value">{{ money(contribution.totalAmount) }}</span>
            <span v-if="contribution.targetAmount" class="ic-muted">{{ t('interactions.outOf', { amount: money(contribution.targetAmount) }) }}</span>
          </div>
          <ContributionProgress :total="contribution.totalAmount" :target="contribution.targetAmount" />
          <div v-if="contribution.remainingAmount !== null" class="total__remaining">
            <span>{{ contribution.goalReached ? t('contribution.goalReached') : t('contribution.remaining') }}</span>
            <strong v-if="!contribution.goalReached">{{ money(contribution.remainingAmount) }}</strong>
          </div>
        </div>

        <SectionTitle>{{ t('contribution.participants', { count: contribution.participantCount }) }}</SectionTitle>
        <ion-list v-if="contribution.participants.length" class="ic-card-list" lines="inset">
          <ion-item v-for="p in contribution.participants" :key="p.user.id">
            <AppAvatar slot="start" :id="p.user.id" :name="p.user.displayName" :url="p.user.avatarUrl" :size="44" :primary="p.isMe" />
            <ion-label>
              <div class="name">{{ p.isMe ? t('interactions.youCapital') : p.user.displayName }}</div>
              <p v-if="p.isInitiator">{{ t('contribution.initiator') }}</p>
              <p v-else-if="p.isMe">{{ t('contribution.yourShare') }}</p>
            </ion-label>
            <!-- Only for its author and the initiator (CLAUDE.md règle 3): the API omits it otherwise. -->
            <strong v-if="p.amount" slot="end">{{ money(p.amount) }}</strong>
            <span v-else slot="end" class="private-amount">
              <ion-icon :icon="lockClosedOutline" aria-hidden="true" />{{ t('contribution.privateAmount') }}
            </span>
          </ion-item>
        </ion-list>
        <EmptyState v-else>{{ t('contribution.noParticipants') }}</EmptyState>

        <div class="declarative" role="note">
          <ion-icon :icon="shieldCheckmarkOutline" aria-hidden="true" />
          <p>
            <strong>{{ t('contribution.declarativeTitle') }}</strong>
            {{ t('contribution.declarativeBody', { name: contribution.isInitiator ? t('interactions.you') : (contribution.initiator?.displayName ?? t('contribution.formerMember')) }) }}
          </p>
        </div>

        <form v-if="isOpen && canPledge" class="pledge" @submit.prevent="savePledge">
          <div class="label">{{ t('contribution.myShare') }}</div>
          <div class="pledge__row">
            <ion-input
              ref="amountInput"
              v-model="amount"
              class="ic-field"
              fill="outline"
              inputmode="decimal"
              :aria-label="t('contribution.myShare')"
              placeholder="0,00"
            />
            <span class="currency">{{ contribution.currency }}</span>
          </div>
          <ion-text v-if="error" color="danger"><p>{{ error }}</p></ion-text>
          <ion-button color="dark" expand="block" type="submit" :disabled="busy || !amount.trim()">
            {{ contribution.myPledge ? t('contribution.updateShare') : t('contribution.declareShare') }}
          </ion-button>
          <ion-button v-if="contribution.myPledge" fill="clear" color="danger" expand="block" :disabled="busy" @click="withdraw">
            {{ t('contribution.withdrawShare') }}
          </ion-button>
        </form>
      </template>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import { ellipsisHorizontal, helpCircleOutline, lockClosedOutline, shieldCheckmarkOutline } from 'ionicons/icons';
import {
  actionSheetController,
  alertController,
  IonButton,
  IonContent,
  IonIcon,
  IonInput,
  IonItem,
  IonLabel,
  IonList,
  IonPage,
  IonText,
  onIonViewWillEnter,
  toastController,
} from '@ionic/vue';
import { ApiError, isNetworkError } from '../services/api';
import { interactionsApi } from '../services/interactions';
import { useFriendsStore } from '../stores/friends';
import { useErrorMessage } from '../composables/useErrorMessage';
import { useLocalRefresh } from '../composables/useLocalRefresh';
import { formatPrice } from '../utils/price';
import AppAvatar from '../components/AppAvatar.vue';
import ContributionProgress from '../components/ContributionProgress.vue';
import EmptyState from '../components/EmptyState.vue';
import SecretBand from '../components/SecretBand.vue';
import SectionTitle from '../components/SectionTitle.vue';
import TopBar from '../components/TopBar.vue';
import type { Contribution } from '../types/idea';

const { t, locale } = useI18n();
const route = useRoute();
const friendsStore = useFriendsStore();
const { describe } = useErrorMessage();

const id = String(route.params.id);
const contribution = ref<Contribution | null>(null);
const notFound = ref(false);
const amount = ref('');
const busy = ref(false);
const error = ref('');
const amountInput = ref<InstanceType<typeof IonInput>>();

const isOpen = computed(() => 'open' === contribution.value?.status);
const canPledge = computed(() => 'active' === contribution.value?.idea?.status);
const ownerName = computed(
  () => friendsStore.friends.find((f) => f.user.id === contribution.value?.idea?.ownerId)?.user.displayName ?? '',
);

const money = (value: string | null) => formatPrice(value, contribution.value?.currency ?? 'EUR', locale.value) ?? '';

function apply(c: Contribution): void {
  contribution.value = c;
  amount.value = c.myPledge ? c.myPledge.replace(/\.00$/, '').replace('.', ',') : '';
}

onIonViewWillEnter(async () => {
  if (!friendsStore.friends.length) void friendsStore.fetchFriends();
  if (!(await load())) return;
  // Coming from "Cotiser à plusieurs" / "Ouvrir à plusieurs": offer to pledge right away (spec §5.7).
  if ('1' === route.query.pledge && !contribution.value?.myPledge) {
    await nextTick();
    await amountInput.value?.$el.setFocus();
  }
});

async function load(): Promise<boolean> {
  try {
    apply(await interactionsApi.contribution(id));
    notFound.value = false;

    return true;
  } catch (e) {
    if ((e instanceof ApiError && 404 === e.status) || isNetworkError(e)) {
      contribution.value = null;
      notFound.value = true;

      return false;
    }
    throw e;
  }
}

// Re-read after a sync, without clobbering an amount being typed.
useLocalRefresh(async () => {
  const typed = amount.value;
  await load();
  if (typed && typed !== amount.value) amount.value = typed;
});

async function run(action: () => Promise<Contribution>): Promise<void> {
  busy.value = true;
  error.value = '';
  try {
    apply(await action());
  } catch (e) {
    error.value = describe(e);
    // e.g. closed in the meantime (spec §8): show the current state.
    if (e instanceof ApiError && 'contribution.closed' === e.code) apply(await interactionsApi.contribution(id));
  } finally {
    busy.value = false;
  }
}

function savePledge(): Promise<void> {
  return run(() => interactionsApi.pledge(id, amount.value.trim()));
}

function withdraw(): Promise<void> {
  return run(() => interactionsApi.withdrawPledge(id));
}

async function initiatorMenu(): Promise<void> {
  const sheet = await actionSheetController.create({
    buttons: [
      { text: t('contribution.editTarget'), handler: () => void editTarget() },
      { text: t('contribution.close'), role: 'destructive', handler: () => void confirmClose() },
      { text: t('common.cancel'), role: 'cancel' },
    ],
  });
  await sheet.present();
}

async function editTarget(): Promise<void> {
  const alert = await alertController.create({
    header: t('contribution.editTarget'),
    message: t('contribution.editTargetHint'),
    inputs: [{ name: 'target', type: 'text', value: contribution.value?.targetAmount ?? '', attributes: { inputmode: 'decimal' } }],
    buttons: [
      { text: t('common.cancel'), role: 'cancel' },
      { text: t('common.save'), role: 'confirm' },
    ],
  });
  await alert.present();
  const result = await alert.onDidDismiss();
  if ('confirm' !== result.role) return;

  const target = String(result.data.values.target ?? '').trim();
  await run(() => interactionsApi.setTarget(id, target || null));
  if (error.value) await (await toastController.create({ message: error.value, duration: 3000, color: 'danger' })).present();
}

async function confirmClose(): Promise<void> {
  const alert = await alertController.create({
    header: t('contribution.closeConfirm.title'),
    message: t('contribution.closeConfirm.message'),
    buttons: [
      { text: t('common.cancel'), role: 'cancel' },
      { text: t('contribution.close'), role: 'destructive' },
    ],
  });
  await alert.present();
  if ('destructive' !== (await alert.onDidDismiss()).role) return;

  await run(() => interactionsApi.closeContribution(id));
}
</script>

<style scoped>
.subtitle {
  font-family: var(--ic-font-body);
  font-size: 0.9375rem;
  color: var(--ic-text-secondary);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.closed-note {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 12px;
  padding: 12px 14px;
  border-radius: var(--ic-radius-card);
  background: var(--ic-surface-muted);
  font-weight: 600;
}

.total {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-top: 16px;
  padding: 20px;
  border-radius: var(--ic-radius-block);
}

.total__label {
  font-size: 0.8125rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--ic-text-secondary);
}

.total__amounts {
  display: flex;
  align-items: baseline;
  gap: 8px;
}

.total__value {
  font-family: var(--ic-font-display);
  font-size: 3rem;
  line-height: 1;
}

.total__remaining {
  display: flex;
  justify-content: space-between;
  font-size: 0.9375rem;
}

.name {
  font-size: 1rem;
  font-weight: 700;
}

ion-item ion-label p {
  color: var(--ic-text-secondary);
}

.private-amount {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  color: var(--ic-text-secondary);
  font-size: 0.875rem;
}

.declarative {
  display: flex;
  gap: 10px;
  margin-top: 16px;
  padding: 14px 16px;
  border-radius: var(--ic-radius-card);
  background: var(--ic-surface-muted);
}

.declarative ion-icon {
  flex-shrink: 0;
  font-size: 1.125rem;
  margin-top: 2px;
}

.declarative p {
  margin: 0;
  font-size: 0.9375rem;
  line-height: 1.45;
}

.pledge {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin: 20px 0 32px;
}

.pledge ion-button {
  margin: 0;
}

.label {
  font-size: 0.9375rem;
  font-weight: 700;
}

.pledge__row {
  display: grid;
  grid-template-columns: 1fr 90px;
  gap: 10px;
  align-items: stretch;
}

.currency {
  display: flex;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--ic-border);
  border-radius: var(--ic-radius-field);
  background: var(--ic-surface-muted);
  font-weight: 700;
}
</style>
