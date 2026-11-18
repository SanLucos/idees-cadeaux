<template>
  <ion-page>
    <ion-header>
      <ion-toolbar>
        <ion-title>{{ t('auth.emailSignIn.title') }}</ion-title>
      </ion-toolbar>
    </ion-header>
    <ion-content class="ion-padding">
      <!-- Opened from the email's link: signing in straight away. -->
      <div v-if="fromLink && loading" class="ion-text-center ion-margin-top">
        <ion-spinner />
        <p>{{ t('auth.emailSignIn.signingIn') }}</p>
      </div>

      <form v-else-if="!sent" @submit.prevent="send">
        <p>{{ t('auth.emailSignIn.instructions') }}</p>
        <ion-list>
          <ion-item>
            <ion-input
              v-model="email"
              :label="t('auth.email')"
              label-placement="stacked"
              type="email"
              autocomplete="email"
              required
              data-testid="email-sign-in-email"
            />
          </ion-item>
        </ion-list>

        <ion-text color="danger" v-if="error">
          <p>{{ error }}</p>
        </ion-text>

        <ion-button expand="block" type="submit" class="ion-margin-top" :disabled="loading" data-testid="email-sign-in-send">
          {{ t('auth.emailSignIn.send') }}
        </ion-button>
      </form>

      <form v-else @submit.prevent="signIn">
        <p>{{ t('auth.emailSignIn.sent', { email }) }}</p>
        <ion-list>
          <ion-item>
            <ion-input
              v-model="code"
              :label="t('auth.emailSignIn.code')"
              label-placement="stacked"
              inputmode="numeric"
              autocomplete="one-time-code"
              :maxlength="6"
              required
              data-testid="email-sign-in-code"
            />
          </ion-item>
        </ion-list>

        <ion-text color="danger" v-if="error">
          <p>{{ error }}</p>
        </ion-text>
        <ion-text color="success" v-if="info">
          <p>{{ info }}</p>
        </ion-text>

        <ion-button expand="block" type="submit" class="ion-margin-top" :disabled="loading" data-testid="email-sign-in-submit">
          {{ t('auth.emailSignIn.submit') }}
        </ion-button>
        <ion-button expand="block" fill="clear" :disabled="loading" @click="resend">
          {{ t('auth.emailSignIn.resend') }}
        </ion-button>
        <ion-button expand="block" fill="clear" :disabled="loading" @click="changeEmail">
          {{ t('auth.emailSignIn.changeEmail') }}
        </ion-button>
      </form>

      <ion-text class="ion-text-center" style="display: block; margin-top: 2rem;">
        <router-link to="/login">{{ t('auth.emailSignIn.withPassword') }}</router-link>
      </ion-text>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import {
  IonButton,
  IonContent,
  IonHeader,
  IonInput,
  IonItem,
  IonList,
  IonPage,
  IonSpinner,
  IonText,
  IonTitle,
  IonToolbar,
} from '@ionic/vue';
import { useAuthStore } from '../../stores/auth';
import { useErrorMessage } from '../../composables/useErrorMessage';

/**
 * Sign-in without a password (spec §11 décision 68): an email with a
 * code and a link carrying it (`/login/email?email=…&code=…`). Works
 * for every account, and creates the account on the first sign-in.
 */
const { t, locale } = useI18n();
const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const { describe } = useErrorMessage();

const queryEmail = typeof route.query.email === 'string' ? route.query.email : '';
const queryCode = typeof route.query.code === 'string' ? route.query.code : '';
const fromLink = ref('' !== queryEmail && '' !== queryCode);

const email = ref(queryEmail);
const code = ref(queryCode);
const sent = ref(fromLink.value);
const loading = ref(fromLink.value);
const error = ref('');
const info = ref('');

async function send(): Promise<void> {
  loading.value = true;
  error.value = '';
  info.value = '';
  try {
    await auth.sendSignInEmail(email.value.trim(), locale.value);
    sent.value = true;
  } catch (e) {
    error.value = describe(e);
  } finally {
    loading.value = false;
  }
}

async function resend(): Promise<void> {
  code.value = '';
  await send();
  if (!error.value) info.value = t('auth.emailSignIn.resent');
}

function changeEmail(): void {
  sent.value = false;
  code.value = '';
  error.value = '';
  info.value = '';
}

async function signIn(): Promise<void> {
  loading.value = true;
  error.value = '';
  info.value = '';
  try {
    await auth.emailSignIn(email.value.trim(), code.value.trim(), locale.value);
    // Onboarding and a pending share link are taken over by the router.
    await router.replace('/tabs/list');
  } catch (e) {
    error.value = describe(e);
    // A dead link falls back to typing a code or asking for a new one.
    fromLink.value = false;
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  if (fromLink.value) void signIn();
});
</script>
