<template>
  <section>
    <SectionTitle>
      {{ t('profile.sizes.title') }}
      <template #end>{{ t('profile.visibleToFriends') }}</template>
    </SectionTitle>

    <ion-list class="ic-card-list" lines="inset">
      <ion-reorder-group :disabled="readonly" @ion-item-reorder="onReorder">
        <ion-item v-for="size in sizes" :key="size['@id']">
          <ion-reorder v-if="!readonly" slot="start" />
          <ion-label class="size-label">
            {{ size.label }}
            <p v-if="size.note">{{ size.note }}</p>
          </ion-label>
          <span slot="end" class="size-value">{{ size.value }}</span>
          <ion-button
            v-if="!readonly"
            slot="end"
            fill="clear"
            color="medium"
            :aria-label="t('common.actionsFor', { name: size.label })"
            @click="openActions(size)"
          >
            <ion-icon slot="icon-only" :icon="ellipsisHorizontal" />
          </ion-button>
        </ion-item>
      </ion-reorder-group>
      <ion-item v-if="!sizes.length && readonly">
        <ion-label class="ic-muted">{{ t('profile.sizes.empty') }}</ion-label>
      </ion-item>
      <ion-item v-if="!readonly" button :detail="false" @click="openForm()">
        <ion-icon slot="start" :icon="add" color="primary" />
        <ion-label color="primary" class="add-label">{{ t('profile.sizes.add') }}</ion-label>
      </ion-item>
    </ion-list>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { add, ellipsisHorizontal } from 'ionicons/icons';
import {
  actionSheetController,
  IonButton,
  IonIcon,
  IonItem,
  IonLabel,
  IonList,
  IonReorder,
  IonReorderGroup,
  modalController,
  type ItemReorderEventDetail,
} from '@ionic/vue';
import { useProfileDetailsStore } from '../stores/profileDetails';
import { useLocalRefresh } from '../composables/useLocalRefresh';
import SectionTitle from './SectionTitle.vue';
import SizeFormModal from './SizeFormModal.vue';
import SizeHistoryModal from './SizeHistoryModal.vue';
import type { ProfileSize } from '../types/profile';

/** Without `entries`, shows and edits my own sizes; with them, a friend's, read-only. */
const props = defineProps<{ entries?: ProfileSize[] }>();

const { t } = useI18n();
const store = useProfileDetailsStore();
const readonly = computed(() => undefined !== props.entries);
const sizes = computed(() => props.entries ?? store.sizes);

onMounted(() => {
  if (!readonly.value) store.fetchSizes();
});
useLocalRefresh(() => {
  if (!readonly.value) void store.fetchSizes();
});

async function onReorder(event: CustomEvent<ItemReorderEventDetail>): Promise<void> {
  const { from, to } = event.detail;
  // Let the store own the order: complete(false) keeps Ionic from moving the DOM itself.
  event.detail.complete(false);
  await store.moveSize(from, to);
}

async function openForm(size?: ProfileSize): Promise<void> {
  const modal = await modalController.create({ component: SizeFormModal, componentProps: { size }, breakpoints: [0, 0.75, 1], initialBreakpoint: 0.75 });
  await modal.present();
}

async function openHistory(size: ProfileSize): Promise<void> {
  const modal = await modalController.create({ component: SizeHistoryModal, componentProps: { size }, breakpoints: [0, 0.75, 1], initialBreakpoint: 0.75 });
  await modal.present();
}

async function openActions(size: ProfileSize): Promise<void> {
  const sheet = await actionSheetController.create({
    header: `${size.label} : ${size.value}`,
    buttons: [
      { text: t('profile.edit'), handler: () => void openForm(size) },
      // Only what the server sent: a size whose value never changed has nothing to show.
      ...((size.history?.length ?? 0) > 1 ? [{ text: t('profile.sizes.history.title'), handler: () => void openHistory(size) }] : []),
      { text: t('common.delete'), role: 'destructive', handler: () => store.removeSize(size) },
      { text: t('common.cancel'), role: 'cancel' },
    ],
  });
  await sheet.present();
}
</script>

<style scoped>
.size-label {
  color: var(--ic-text-secondary);
}

.size-value {
  font-weight: 700;
}

.add-label {
  font-weight: 700;
}
</style>
