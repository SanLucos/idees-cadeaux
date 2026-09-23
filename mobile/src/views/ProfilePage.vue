<template>
  <ion-page>
    <ion-content>
      <ScreenHeader>{{ t('profile.title') }}</ScreenHeader>

      <!-- Profil actif (Profil.png): me or one of my children (spec §5.15). -->
      <button v-if="activeProfile.children.length" type="button" class="ic-card switcher" @click="chooseProfile">
        <AppAvatar :id="current.id" :name="current.name" :url="current.avatarUrl" :size="32" :primary="!activeProfile.isActing" />
        <span class="switcher__label">
          {{ t('profile.activeProfile') }} <strong>{{ current.name }}</strong>
        </span>
        <span v-if="otherNames" class="ic-muted switcher__others">{{ t('profile.orOthers', { names: otherNames }) }}</span>
        <ion-icon :icon="chevronDown" aria-hidden="true" />
      </button>

      <div class="identity">
        <AppAvatar :id="current.id" :name="current.name" :url="current.avatarUrl" :size="88" :primary="!activeProfile.isActing" />
        <div class="identity__text">
          <div class="identity__name">{{ current.name }}</div>
          <div v-if="current.birthday" class="ic-muted">{{ t('profile.birthdayOn', { date: current.birthday }) }}</div>
          <div v-if="activeProfile.isActing" class="ic-muted">{{ t('children.managedByYou') }}</div>
        </div>
        <ion-button class="ic-button-surface" :router-link="activeProfile.active ? `/profile/children/${activeProfile.active.id}` : '/profile/edit'">
          {{ t('profile.edit') }}
        </ion-button>
      </div>

      <!-- Keyed on the active profile: sizes and preferences reload as the child's. -->
      <ProfileSizesSection :key="`sizes-${activeProfile.activeId ?? 'me'}`" />
      <ProfilePreferencesSection :key="`prefs-${activeProfile.activeId ?? 'me'}`" />

      <template v-if="!activeProfile.isActing">
        <SectionTitle>{{ t('children.title') }}</SectionTitle>
        <ion-list class="ic-card-list" lines="inset">
          <ion-item v-for="child in activeProfile.children" :key="child.id" :router-link="`/profile/children/${child.id}`" button detail>
            <AppAvatar slot="start" :id="child.id" :name="child.displayName" :url="child.avatarUrl" :size="44" />
            <ion-label>
              <div class="name">{{ child.displayName }}</div>
              <p>{{ childSubtitle(child) }}</p>
            </ion-label>
          </ion-item>
          <ion-item button :detail="false" router-link="/profile/children/new">
            <ion-icon slot="start" :icon="add" color="primary" />
            <ion-label color="primary" class="add-label">{{ t('children.create') }}</ion-label>
          </ion-item>
        </ion-list>

        <SectionTitle>{{ t('profile.settings.title') }}</SectionTitle>
        <ion-list class="ic-card-list" lines="inset">
          <ion-item button @click="chooseLanguage">
            <ion-icon slot="start" :icon="globeOutline" aria-hidden="true" />
            <ion-label>{{ t('profile.settings.language') }}</ion-label>
            <ion-note slot="end">{{ t(`locales.${locale}`) }}</ion-note>
          </ion-item>
          <ion-item button @click="logout">
            <ion-icon slot="start" :icon="logOutOutline" aria-hidden="true" />
            <ion-label>{{ t('nav.logout') }}</ion-label>
          </ion-item>
        </ion-list>
      </template>

      <ion-button v-else class="ic-button-surface back-to-me" expand="block" @click="activeProfile.switchTo(null)">
        {{ t('children.backToMe', { name: auth.user?.displayName }) }}
      </ion-button>
      <div class="bottom-space" />
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { add, chevronDown, globeOutline, logOutOutline } from 'ionicons/icons';
import { actionSheetController, IonButton, IonContent, IonIcon, IonItem, IonLabel, IonList, IonNote, IonPage } from '@ionic/vue';
import { useAuthStore } from '../stores/auth';
import { useActiveProfileStore } from '../stores/activeProfile';
import { SUPPORTED_LOCALES } from '../i18n';
import { formatBirthday } from '../utils/birthday';
import AppAvatar from '../components/AppAvatar.vue';
import ProfilePreferencesSection from '../components/ProfilePreferencesSection.vue';
import ProfileSizesSection from '../components/ProfileSizesSection.vue';
import ScreenHeader from '../components/ScreenHeader.vue';
import SectionTitle from '../components/SectionTitle.vue';
import type { ManagedProfile } from '../types/user';

const { t, locale } = useI18n();
const router = useRouter();
const auth = useAuthStore();
const activeProfile = useActiveProfileStore();

/** Whoever the screen is about: me, or the active child. */
const current = computed(() => {
  const child = activeProfile.active;
  const source = child ?? auth.user;
  const birthday = source?.birthDay && source.birthMonth ? formatBirthday(source.birthDay, source.birthMonth, locale.value) : null;

  return { id: source?.id ?? '', name: source?.displayName ?? '', avatarUrl: source?.avatarUrl ?? null, birthday };
});

const otherNames = computed(() =>
  [auth.user, ...activeProfile.children]
    .filter((p) => p && p.id !== current.value.id)
    .map((p) => p!.displayName)
    .join(', '),
);

onMounted(() => {
  auth.fetchMe();
  activeProfile.fetchChildren();
});

function childSubtitle(child: ManagedProfile): string {
  if (child.deletionScheduledAt) {
    return t('children.deletionOn', { date: new Date(child.deletionScheduledAt).toLocaleDateString(locale.value) });
  }

  return [t('children.managedByYou'), t('friends.ideaCount', { count: child.ideaCount }, child.ideaCount)].join(' · ');
}

async function chooseProfile(): Promise<void> {
  const sheet = await actionSheetController.create({
    header: t('profile.chooseActive'),
    buttons: [
      { text: auth.user?.displayName ?? '', handler: () => activeProfile.switchTo(null) },
      ...activeProfile.children
        .filter((c) => !c.deletionScheduledAt)
        .map((c) => ({ text: c.displayName ?? '', handler: () => activeProfile.switchTo(c.id) })),
      { text: t('common.cancel'), role: 'cancel' },
    ],
  });
  await sheet.present();
}

async function chooseLanguage(): Promise<void> {
  const sheet = await actionSheetController.create({
    header: t('profile.settings.language'),
    buttons: [
      ...SUPPORTED_LOCALES.map((code) => ({ text: t(`locales.${code}`), handler: () => auth.updateProfile({ locale: code }) })),
      { text: t('common.cancel'), role: 'cancel' },
    ],
  });
  await sheet.present();
}

async function logout(): Promise<void> {
  activeProfile.reset();
  await auth.logout();
  router.replace('/login');
}
</script>

<style scoped>
.switcher {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 10px 14px;
  color: var(--ion-text-color);
  font: 15px var(--ic-font-body);
  text-align: left;
}

.switcher__label {
  flex-grow: 1;
  min-width: 0;
}

.switcher__others {
  max-width: 35%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 14px;
}

.identity {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-top: 16px;
}

.identity__text {
  flex-grow: 1;
  min-width: 0;
}

.identity__name {
  font-family: var(--ic-font-display);
  font-size: 28px;
  line-height: 1.2;
}

.name {
  font-size: 16px;
  font-weight: 700;
}

ion-item ion-label p {
  color: var(--ic-text-secondary);
}

.add-label {
  font-weight: 700;
}

.back-to-me {
  margin-top: 20px;
}

.bottom-space {
  height: 24px;
}
</style>
