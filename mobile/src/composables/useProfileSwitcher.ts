import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { actionSheetController } from '@ionic/vue';
import { useAuthStore } from '../stores/auth';
import { useActiveProfileStore } from '../stores/activeProfile';

/**
 * The « Profil actif » choice (spec §5.15, §11 décision 64): me or one
 * of my children, from the greeting of any tab or from the Profil screen.
 */
export function useProfileSwitcher() {
  const { t } = useI18n();
  const auth = useAuthStore();
  const activeProfile = useActiveProfileStore();

  /** Children I can act for: not those about to be deleted. */
  const switchable = computed(() => activeProfile.children.filter((c) => !c.deletionScheduledAt));
  const canSwitch = computed(() => switchable.value.length > 0);

  /** Whoever the app currently acts as: me, or the active child. */
  const currentName = computed(() => (activeProfile.active ?? auth.user)?.displayName ?? '');

  async function choose(): Promise<void> {
    const option = (id: string | null, name: string | null | undefined) => ({
      text: name ?? '',
      cssClass: id === activeProfile.activeId ? 'ic-action-current' : undefined,
      handler: () => activeProfile.switchTo(id),
    });
    const sheet = await actionSheetController.create({
      header: t('profile.chooseActive'),
      buttons: [
        option(null, auth.user?.displayName),
        ...switchable.value.map((c) => option(c.id, c.displayName)),
        { text: t('common.cancel'), role: 'cancel' },
      ],
    });
    await sheet.present();
  }

  return { canSwitch, currentName, choose };
}
