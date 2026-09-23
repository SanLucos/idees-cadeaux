<template>
  <ion-page>
    <ion-content>
      <ScreenHeader>{{ t('profile.title') }}</ScreenHeader>

      <div v-if="auth.user" class="identity">
        <AppAvatar :id="auth.user.id" :name="auth.user.displayName" :url="auth.user.avatarUrl" :size="88" primary />
        <div class="identity__text">
          <div class="identity__name">{{ auth.user.displayName }}</div>
          <div v-if="auth.user.birthDay && auth.user.birthMonth" class="ic-muted">
            {{ t('profile.birthdayOn', { date: formatBirthday(auth.user.birthDay, auth.user.birthMonth, locale) }) }}
          </div>
        </div>
        <ion-button class="ic-button-surface" router-link="/profile/edit">{{ t('profile.edit') }}</ion-button>
      </div>

      <ProfileSizesSection />
      <ProfilePreferencesSection />

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
      <div class="bottom-space" />
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { globeOutline, logOutOutline } from 'ionicons/icons';
import { actionSheetController, IonButton, IonContent, IonIcon, IonItem, IonLabel, IonList, IonNote, IonPage } from '@ionic/vue';
import { useAuthStore } from '../stores/auth';
import { SUPPORTED_LOCALES } from '../i18n';
import { formatBirthday } from '../utils/birthday';
import AppAvatar from '../components/AppAvatar.vue';
import ProfilePreferencesSection from '../components/ProfilePreferencesSection.vue';
import ProfileSizesSection from '../components/ProfileSizesSection.vue';
import ScreenHeader from '../components/ScreenHeader.vue';
import SectionTitle from '../components/SectionTitle.vue';

const { t, locale } = useI18n();
const router = useRouter();
const auth = useAuthStore();

onMounted(() => auth.fetchMe());

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
  await auth.logout();
  router.replace('/login');
}
</script>

<style scoped>
.identity {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-top: 8px;
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

.bottom-space {
  height: 24px;
}
</style>
