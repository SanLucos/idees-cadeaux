<template>
  <ion-page>
    <ion-content>
      <TopBar default-href="/tabs/profile" close-icon>{{ t('children.createTitle') }}</TopBar>
      <p class="ic-muted intro">{{ t('children.createIntro') }}</p>

      <form class="form" @submit.prevent="submit">
        <ion-input
          v-model="displayName"
          class="ic-field"
          fill="outline"
          :label="t('profile.pseudoLabel')"
          label-placement="stacked"
          :minlength="2"
          :maxlength="30"
          :counter="true"
          required
        />
        <div class="field-label">{{ t('onboarding.birthdayTitle') }}</div>
        <div class="birthday">
          <ion-input v-model.number="birthDay" class="ic-field" fill="outline" :label="t('onboarding.birthDay')" label-placement="stacked" type="number" :min="1" :max="31" />
          <ion-input v-model.number="birthMonth" class="ic-field" fill="outline" :label="t('onboarding.birthMonth')" label-placement="stacked" type="number" :min="1" :max="12" />
        </div>

        <!-- Spec §5.15 / §5.13: explicit consent, recorded with a timestamp. -->
        <ion-checkbox v-model="consent" label-placement="end" justify="start" class="consent">
          <span class="consent__text">{{ t('children.consent') }}</span>
        </ion-checkbox>

        <ion-text v-if="error" color="danger"><p>{{ error }}</p></ion-text>
        <ion-button expand="block" type="submit" :disabled="saving || !consent || displayName.trim().length < 2">
          {{ t('children.createSubmit') }}
        </ion-button>
      </form>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { IonButton, IonCheckbox, IonContent, IonInput, IonPage, IonText, useIonRouter } from '@ionic/vue';
import { useActiveProfileStore } from '../stores/activeProfile';
import { useErrorMessage } from '../composables/useErrorMessage';
import TopBar from '../components/TopBar.vue';

const { t } = useI18n();
const ionRouter = useIonRouter();
const activeProfile = useActiveProfileStore();
const { describe } = useErrorMessage();

const displayName = ref('');
const birthDay = ref<number | null>(null);
const birthMonth = ref<number | null>(null);
const consent = ref(false);
const saving = ref(false);
const error = ref('');

async function submit(): Promise<void> {
  saving.value = true;
  error.value = '';
  try {
    const child = await activeProfile.create({
      displayName: displayName.value.trim(),
      birthDay: birthDay.value || null,
      birthMonth: birthMonth.value || null,
      parentalConsent: consent.value,
    });
    ionRouter.navigate(`/profile/children/${child.id}`, 'forward', 'replace');
  } catch (e) {
    error.value = describe(e);
  } finally {
    saving.value = false;
  }
}
</script>

<style scoped>
.intro {
  margin: 0 0 16px;
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

.consent {
  padding: 12px 14px;
  border: 1px solid var(--ic-border);
  border-radius: var(--ic-radius-card);
  background: var(--ic-surface);
}

.consent__text {
  white-space: normal;
  line-height: 1.4;
}
</style>
