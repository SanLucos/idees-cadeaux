<template>
  <ion-page>
    <ion-header>
      <ion-toolbar>
        <ion-title>{{ t('profile.title') }}</ion-title>
        <ion-buttons slot="end">
          <ion-button @click="logout">{{ t('nav.logout') }}</ion-button>
        </ion-buttons>
      </ion-toolbar>
    </ion-header>
    <ion-content class="ion-padding">
      <div class="ion-text-center">
        <ion-avatar style="width: 96px; height: 96px; margin: 0 auto;">
          <img v-if="auth.user?.avatarUrl" :src="auth.user.avatarUrl" alt="" />
          <ion-icon v-else :icon="personCircleOutline" style="width: 100%; height: 100%;" />
        </ion-avatar>
        <input ref="fileInput" type="file" accept="image/png,image/jpeg,image/webp" hidden @change="onAvatarChange" />
        <ion-button fill="clear" size="small" @click="fileInput?.click()">{{ t('profile.changeAvatar') }}</ion-button>
      </div>

      <form @submit.prevent="saveProfile">
        <ion-list>
          <ion-item>
            <ion-input v-model="displayName" :label="t('profile.pseudoLabel')" label-placement="stacked" :minlength="2" :maxlength="30" />
          </ion-item>
          <ion-item>
            <ion-input v-model.number="birthDay" label-placement="stacked" :label="t('onboarding.birthDay')" type="number" :min="1" :max="31" />
          </ion-item>
          <ion-item>
            <ion-input v-model.number="birthMonth" label-placement="stacked" :label="t('onboarding.birthMonth')" type="number" :min="1" :max="12" />
          </ion-item>
        </ion-list>

        <ion-text color="danger" v-if="error"><p>{{ error }}</p></ion-text>
        <ion-text color="success" v-if="saved"><p>{{ t('profile.saved') }}</p></ion-text>

        <ion-button expand="block" type="submit" class="ion-margin-top" :disabled="loading">
          {{ t('profile.save') }}
        </ion-button>
      </form>

      <ProfileSizesSection class="ion-margin-top" />
      <ProfilePreferencesSection class="ion-margin-top" />
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { personCircleOutline } from 'ionicons/icons';
import {
  IonAvatar,
  IonButton,
  IonButtons,
  IonContent,
  IonHeader,
  IonIcon,
  IonInput,
  IonItem,
  IonList,
  IonPage,
  IonText,
  IonTitle,
  IonToolbar,
} from '@ionic/vue';
import { useAuthStore } from '../stores/auth';
import { useErrorMessage } from '../composables/useErrorMessage';
import ProfileSizesSection from '../components/ProfileSizesSection.vue';
import ProfilePreferencesSection from '../components/ProfilePreferencesSection.vue';

const { t } = useI18n();
const router = useRouter();
const auth = useAuthStore();
const { describe } = useErrorMessage();

const displayName = ref(auth.user?.displayName ?? '');
const birthDay = ref<number | null>(auth.user?.birthDay ?? null);
const birthMonth = ref<number | null>(auth.user?.birthMonth ?? null);
const loading = ref(false);
const saved = ref(false);
const error = ref('');
const fileInput = ref<HTMLInputElement>();

onMounted(async () => {
  await auth.fetchMe();
  displayName.value = auth.user?.displayName ?? '';
  birthDay.value = auth.user?.birthDay ?? null;
  birthMonth.value = auth.user?.birthMonth ?? null;
});

async function saveProfile(): Promise<void> {
  loading.value = true;
  error.value = '';
  saved.value = false;
  try {
    await auth.updateProfile({ displayName: displayName.value, birthDay: birthDay.value, birthMonth: birthMonth.value });
    saved.value = true;
  } catch (e) {
    error.value = describe(e);
  } finally {
    loading.value = false;
  }
}

async function onAvatarChange(event: Event): Promise<void> {
  const file = (event.target as HTMLInputElement).files?.[0];
  if (!file) return;
  error.value = '';
  try {
    await auth.uploadAvatar(file);
  } catch (e) {
    error.value = describe(e);
  }
}

async function logout(): Promise<void> {
  await auth.logout();
  router.replace('/login');
}
</script>
