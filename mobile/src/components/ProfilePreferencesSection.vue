<template>
  <section>
    <SectionTitle>{{ t('profile.preferences.title') }}</SectionTitle>

    <div class="ic-card block">
      <p v-if="!preferences.length" class="ic-muted empty">{{ t('profile.preferences.empty') }}</p>
      <div v-for="group in groups" :key="group.category" class="group">
        <h3>{{ t(`profile.preferences.category_${group.category}_plural`) }}</h3>
        <div class="chips">
          <ion-chip v-press
            v-for="pref in group.items"
            :key="pref['@id']"
            class="pref-chip"
           
            @click="!readonly && openActions(pref)"
          >
            {{ chipText(pref) }}
          </ion-chip>
        </div>
      </div>
      <ion-button v-if="!readonly" fill="clear" class="add" @click="openForm">
        <ion-icon aria-hidden="true" slot="start" :icon="add" />
        {{ t('profile.preferences.add') }}
      </ion-button>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { add } from 'ionicons/icons';
import { actionSheetController, IonButton, IonChip, IonIcon, modalController } from '@ionic/vue';
import { useProfileDetailsStore } from '../stores/profileDetails';
import { useLocalRefresh } from '../composables/useLocalRefresh';
import SectionTitle from './SectionTitle.vue';
import PreferenceFormModal from './PreferenceFormModal.vue';
import type { ProfilePreference, ProfilePreferenceCategory } from '../types/profile';

const CATEGORIES: ProfilePreferenceCategory[] = ['gout', 'marque', 'autre'];

/** Without `entries`, shows and edits my own preferences; with them, a friend's, read-only. */
const props = defineProps<{ entries?: ProfilePreference[] }>();

const { t } = useI18n();
const store = useProfileDetailsStore();
const readonly = computed(() => undefined !== props.entries);
const preferences = computed(() => props.entries ?? store.preferences);

const groups = computed(() =>
  CATEGORIES.map((category) => ({ category, items: preferences.value.filter((p) => p.category === category) })).filter(
    (g) => g.items.length,
  ),
);

onMounted(() => {
  if (!readonly.value) store.fetchPreferences();
});
useLocalRefresh(() => {
  if (!readonly.value) void store.fetchPreferences();
});

function chipText(pref: ProfilePreference): string {
  return pref.value ? `${pref.label} : ${pref.value}` : pref.label;
}

async function openForm(): Promise<void> {
  const modal = await modalController.create({ component: PreferenceFormModal, breakpoints: [0, 0.6, 1], initialBreakpoint: 0.6 });
  await modal.present();
}

async function openActions(pref: ProfilePreference): Promise<void> {
  const sheet = await actionSheetController.create({
    header: chipText(pref),
    buttons: [
      { text: t('common.delete'), role: 'destructive', handler: () => store.removePreference(pref) },
      { text: t('common.cancel'), role: 'cancel' },
    ],
  });
  await sheet.present();
}
</script>

<style scoped>
.block {
  padding: 16px 16px 8px;
}

.empty {
  margin: 0 0 8px;
}

.group + .group {
  margin-top: 12px;
}

h3 {
  margin: 0 0 8px;
  font-family: var(--ic-font-body);
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--ic-text-secondary);
}

.chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.pref-chip {
  --background: var(--ic-surface-muted);
  --color: var(--ion-text-color);
  margin: 0;
}

.add {
  margin: 8px 0 0 -8px;
  font-weight: 700;
}
</style>
