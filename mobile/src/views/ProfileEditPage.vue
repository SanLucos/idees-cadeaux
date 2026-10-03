<template>
  <ion-page>
    <ion-content>
      <TopBar default-href="/tabs/profile" close-icon>{{ t('profile.editTitle') }}</TopBar>

      <div class="avatar-block">
        <AppAvatar v-if="auth.user" :id="auth.user.id" :name="auth.user.displayName" :url="auth.user.avatarUrl" :size="96" primary />
        <input ref="fileInput" type="file" accept="image/png,image/jpeg,image/webp" hidden @change="onAvatarChange" />
        <ion-button class="ic-button-surface" size="small" @click="fileInput?.click()">
          <ion-icon aria-hidden="true" slot="start" :icon="imageOutline" />
          {{ t('profile.changeAvatar') }}
        </ion-button>
      </div>

      <form class="form" @submit.prevent="saveProfile">
        <ion-input
          v-model="displayName"
          class="ic-field"
          fill="outline"
          :label="t('profile.pseudoLabel')"
          label-placement="stacked"
          :minlength="2"
          :maxlength="30"
          :counter="true"
        />
        <div class="field-label">{{ t('profile.birthdayLabel') }}</div>
        <div class="birthday">
          <ion-input v-model.number="birthDay" class="ic-field" fill="outline" :label="t('onboarding.birthDay')" label-placement="stacked" type="number" :min="1" :max="31" />
          <ion-input v-model.number="birthMonth" class="ic-field" fill="outline" :label="t('onboarding.birthMonth')" label-placement="stacked" type="number" :min="1" :max="12" />
        </div>

        <ion-text v-if="error" color="danger"><p>{{ error }}</p></ion-text>

        <ion-button expand="block" type="submit" :disabled="loading">{{ t('profile.save') }}</ion-button>
      </form>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { imageOutline } from 'ionicons/icons';
import { IonButton, IonContent, IonIcon, IonInput, IonPage, IonText, useIonRouter } from '@ionic/vue';
import { useAuthStore } from '../stores/auth';
import { useErrorMessage } from '../composables/useErrorMessage';
import AppAvatar from '../components/AppAvatar.vue';
import TopBar from '../components/TopBar.vue';

const { t } = useI18n();
const ionRouter = useIonRouter();
const auth = useAuthStore();
const { describe } = useErrorMessage();

const displayName = ref(auth.user?.displayName ?? '');
const birthDay = ref<number | null>(auth.user?.birthDay ?? null);
const birthMonth = ref<number | null>(auth.user?.birthMonth ?? null);
const loading = ref(false);
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
  try {
    await auth.updateProfile({
      displayName: displayName.value,
      // An emptied number input yields '' — the API expects null for "no birthday".
      birthDay: birthDay.value || null,
      birthMonth: birthMonth.value || null,
    });
    ionRouter.navigate('/tabs/profile', 'back', 'pop');
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
</script>

<style scoped>
.avatar-block {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  margin: 8px 0 20px;
}

.form {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.field-label {
  font-weight: 700;
  font-size: 0.9375rem;
}

.birthday {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
</style>
