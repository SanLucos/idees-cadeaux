<template>
  <ion-page>
    <ion-content>
      <TopBar default-href="/tabs/profile">{{ child?.displayName }}</TopBar>
      <EmptyState v-if="!child && loaded">{{ t('children.notFound') }}</EmptyState>

      <template v-if="child">
        <div class="identity">
          <AppAvatar :id="child.id" :name="child.displayName" :url="child.avatarUrl" :size="80" />
          <div class="identity__text">
            <div class="ic-muted">{{ t('children.managedByYou') }} · {{ t('friends.ideaCount', { count: child.ideaCount }, child.ideaCount) }}</div>
            <input ref="fileInput" type="file" accept="image/png,image/jpeg,image/webp" hidden @change="onAvatar" />
            <ion-button class="ic-button-surface" size="small" :disabled="!!child.deletionScheduledAt" @click="fileInput?.click()">
              {{ t('profile.changeAvatar') }}
            </ion-button>
          </div>
        </div>

        <div v-if="child.deletionScheduledAt" class="deletion" role="status">
          <p>{{ t('children.deletionOn', { date: date(child.deletionScheduledAt) }) }}</p>
          <ion-button class="ic-button-surface" expand="block" @click="run(() => activeProfile.cancelDeletion(child!.id))">
            {{ t('children.cancelDeletion') }}
          </ion-button>
        </div>

        <template v-else>
          <ion-button expand="block" class="manage" @click="manage">
            {{ t('children.manageList', { name: child.displayName }) }}
          </ion-button>
          <!-- Spec §5.13: the manager exports the child's data (link sent to them). -->
          <ion-button class="ic-button-surface" expand="block" :router-link="`/profile/children/${child.id}/export`">
            {{ t('account.export.titleChild', { name: child.displayName }) }}
          </ion-button>
          <!-- Spec §5.16: the manager shares the child's profile from its page. -->
          <ion-button class="ic-button-surface" expand="block" :router-link="`/profile/children/${child.id}/share`">
            {{ t('shareLink.entryChild', { name: child.displayName }) }}
          </ion-button>

          <SectionTitle>{{ t('children.details') }}</SectionTitle>
          <form class="form" @submit.prevent="save">
            <ion-input v-model="displayName" class="ic-field" fill="outline" :label="t('profile.pseudoLabel')" label-placement="stacked" :maxlength="30" />
            <div class="birthday">
              <ion-input v-model.number="birthDay" class="ic-field" fill="outline" :label="t('onboarding.birthDay')" label-placement="stacked" type="number" :min="1" :max="31" />
              <ion-input v-model.number="birthMonth" class="ic-field" fill="outline" :label="t('onboarding.birthMonth')" label-placement="stacked" type="number" :min="1" :max="12" />
            </div>
            <ion-button class="ic-button-surface" expand="block" type="submit" :disabled="busy">{{ t('common.save') }}</ion-button>
          </form>

          <!-- Spec §5.15 "rattacher un email" -->
          <SectionTitle>{{ t('children.attachTitle') }}</SectionTitle>
          <p class="ic-muted explain">{{ t('children.attachExplain', { name: child.displayName }) }}</p>
          <div v-if="child.invitation" class="ic-card pending">
            {{ t('children.invitationPending', { email: child.invitation.email, date: date(child.invitation.expiresAt) }) }}
          </div>
          <form class="form" @submit.prevent="attach">
            <ion-input v-model="email" class="ic-field" fill="outline" type="email" :label="t('auth.email')" label-placement="stacked" />
            <ion-button class="ic-button-surface" expand="block" type="submit" :disabled="busy || !email.trim()">
              {{ child.invitation ? t('children.resendInvitation') : t('children.sendInvitation') }}
            </ion-button>
          </form>

          <SectionTitle>{{ t('children.dangerZone') }}</SectionTitle>
          <ion-button fill="clear" color="danger" expand="block" @click="confirmDelete">{{ t('children.delete') }}</ion-button>
        </template>

        <ion-text v-if="error" color="danger"><p>{{ error }}</p></ion-text>
      </template>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import { alertController, IonButton, IonContent, IonInput, IonPage, IonText, onIonViewWillEnter, toastController, useIonRouter } from '@ionic/vue';
import { useActiveProfileStore } from '../stores/activeProfile';
import { useErrorMessage } from '../composables/useErrorMessage';
import AppAvatar from '../components/AppAvatar.vue';
import EmptyState from '../components/EmptyState.vue';
import SectionTitle from '../components/SectionTitle.vue';
import TopBar from '../components/TopBar.vue';

const { t, locale } = useI18n();
const route = useRoute();
const ionRouter = useIonRouter();
const activeProfile = useActiveProfileStore();
const { describe } = useErrorMessage();

const id = String(route.params.id);
const child = computed(() => activeProfile.children.find((c) => c.id === id) ?? null);
const loaded = ref(false);
const displayName = ref('');
const birthDay = ref<number | null>(null);
const birthMonth = ref<number | null>(null);
const email = ref('');
const busy = ref(false);
const error = ref('');
const fileInput = ref<HTMLInputElement>();

watch(child, (c) => {
  displayName.value = c?.displayName ?? '';
  birthDay.value = c?.birthDay ?? null;
  birthMonth.value = c?.birthMonth ?? null;
}, { immediate: true });

onIonViewWillEnter(async () => {
  await activeProfile.fetchChildren();
  loaded.value = true;
});

const date = (iso: string) => new Date(iso).toLocaleDateString(locale.value, { day: 'numeric', month: 'long', year: 'numeric' });

async function run(action: () => Promise<unknown>): Promise<boolean> {
  busy.value = true;
  error.value = '';
  try {
    await action();

    return true;
  } catch (e) {
    error.value = describe(e);

    return false;
  } finally {
    busy.value = false;
  }
}

function manage(): void {
  activeProfile.switchTo(id);
  ionRouter.navigate('/tabs/list', 'root', 'replace');
}

function save(): Promise<boolean> {
  return run(() => activeProfile.update(id, {
    displayName: displayName.value.trim(),
    birthDay: birthDay.value || null,
    birthMonth: birthMonth.value || null,
  }));
}

async function onAvatar(event: Event): Promise<void> {
  const file = (event.target as HTMLInputElement).files?.[0];
  if (file) await run(() => activeProfile.uploadAvatar(id, file));
}

async function attach(): Promise<void> {
  if (await run(() => activeProfile.attachEmail(id, email.value.trim()))) {
    email.value = '';
    await (await toastController.create({ message: t('children.invitationSent'), duration: 3000, color: 'success' })).present();
  }
}

async function confirmDelete(): Promise<void> {
  const alert = await alertController.create({
    header: t('children.deleteConfirm.title', { name: child.value?.displayName }),
    message: t('children.deleteConfirm.message'),
    buttons: [
      { text: t('common.cancel'), role: 'cancel' },
      { text: t('children.delete'), role: 'destructive' },
    ],
  });
  await alert.present();
  if ('destructive' === (await alert.onDidDismiss()).role) {
    await run(() => activeProfile.scheduleDeletion(id));
  }
}
</script>

<style scoped>
.identity {
  display: flex;
  align-items: center;
  gap: 16px;
}

.identity__text {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 8px;
}

.identity__text ion-button {
  margin: 0;
}

.manage {
  margin-top: 20px;
}

.form {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.form ion-button {
  margin: 0;
}

.birthday {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}

.explain {
  margin: 0 0 12px;
  line-height: 1.4;
}

.pending {
  margin-bottom: 12px;
  padding: 12px 14px;
  font-size: 0.875rem;
}

.deletion {
  margin-top: 20px;
  padding: 16px;
  border-radius: var(--ic-radius-card);
  background: var(--ic-primary-soft);
  color: var(--ic-primary-text-on-soft);
}

.deletion p {
  margin: 0 0 12px;
  font-weight: 600;
}
</style>
