<template>
  <ion-page>
    <ion-content>
      <TopBar :default-href="child ? `/profile/children/${child.id}` : '/tabs/profile'">
        {{ child ? t('account.export.titleChild', { name: child.displayName }) : t('account.export.title') }}
      </TopBar>

      <template v-if="!requested">
        <p class="intro">{{ child ? t('account.export.introChild', { name: child.displayName }) : t('account.export.intro') }}</p>
        <ul class="ic-card contents">
          <li v-for="key in contents" :key="key">{{ t(`account.export.contents.${key}`) }}</li>
        </ul>
        <p class="ic-muted">{{ t('account.export.delivery', { email: auth.user?.email }) }}</p>

        <form class="form" @submit.prevent="submit">
          <ReauthFields v-model="reauth" :has-password="auth.user?.hasPassword ?? true" @error="(e) => (error = describe(e))" />
          <ion-text v-if="error" color="danger"><p>{{ error }}</p></ion-text>
          <ion-button expand="block" type="submit" :disabled="!reauth || busy">{{ t('account.export.submit') }}</ion-button>
        </form>
      </template>

      <EmptyState v-else :icon="mailOutline">{{ t('account.export.requested', { email: auth.user?.email }) }}</EmptyState>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import { IonButton, IonContent, IonPage, IonText } from '@ionic/vue';
import { mailOutline } from 'ionicons/icons';
import { accountApi, type Reauth } from '../services/account';
import { useAuthStore } from '../stores/auth';
import { useActiveProfileStore } from '../stores/activeProfile';
import { useErrorMessage } from '../composables/useErrorMessage';
import EmptyState from '../components/EmptyState.vue';
import ReauthFields from '../components/ReauthFields.vue';
import TopBar from '../components/TopBar.vue';

/**
 * « Exporter mes données » (spec §5.13): re-authentication, then an
 * archive built in the background; its link (48 h) comes by email. A
 * manager exports a child profile the same way (`/profile/children/:id/export`).
 */
const { t } = useI18n();
const { describe } = useErrorMessage();
const route = useRoute();
const auth = useAuthStore();
const activeProfile = useActiveProfileStore();

const child = computed(() => (typeof route.params.id === 'string' ? activeProfile.children.find((c) => c.id === route.params.id) ?? null : null));
const contents = ['profile', 'ideas', 'friends', 'activity', 'images'];

const reauth = ref<Reauth | null>(null);
const busy = ref(false);
const error = ref('');
const requested = ref(false);

async function submit(): Promise<void> {
  if (!reauth.value) return;
  busy.value = true;
  error.value = '';
  try {
    await accountApi.requestExport(reauth.value, child.value?.id);
    requested.value = true;
  } catch (e) {
    error.value = describe(e);
  } finally {
    busy.value = false;
  }
}
</script>

<style scoped>
.intro {
  margin: 4px 0 12px;
  line-height: 1.45;
}

.contents {
  margin: 0 0 12px;
  padding: 12px 16px 12px 32px;
  line-height: 1.7;
}

.form {
  display: flex;
  flex-direction: column;
  gap: 14px;
  margin-top: 8px;
}
</style>
