import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { popoverController } from '@ionic/vue';
import { useAuthStore } from '../stores/auth';
import { useActiveProfileStore } from '../stores/activeProfile';
import { profileTint } from '../utils/profileTint';
import ProfileMenu from '../components/ProfileMenu.vue';

/** One line of the « Profil actif » menu: me (id null) or one of my children. */
export interface ProfileChoice {
  id: string | null;
  avatarId: string;
  name: string;
  avatarUrl: string | null;
  /** The background this profile gives the app, shown as a swatch. */
  tintColor: string;
  current: boolean;
}

/**
 * The « Profil actif » choice (spec §5.15, §11 décision 64): me or one
 * of my children, from the greeting of any tab or from the Profil screen,
 * in a menu that opens right under what was tapped.
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

  const choices = computed((): ProfileChoice[] => {
    const childIds = activeProfile.children.map((c) => c.id);
    const tintOf = (id: string | null) => {
      const tint = profileTint(id, childIds);

      return null === tint ? 'var(--ic-profile-tint-me)' : `var(--ic-profile-tint-${tint})`;
    };

    return [
      { id: null, avatarId: auth.user?.id ?? '', name: auth.user?.displayName ?? '', avatarUrl: auth.user?.avatarUrl ?? null, tintColor: tintOf(null), current: null === activeProfile.activeId },
      ...switchable.value.map((c) => ({
        id: c.id,
        avatarId: c.id,
        name: c.displayName ?? '',
        avatarUrl: c.avatarUrl,
        tintColor: tintOf(c.id),
        current: c.id === activeProfile.activeId,
      })),
    ];
  });

  /** Opens the menu under the tapped element (`event`). */
  async function choose(event?: Event): Promise<void> {
    const popover = await popoverController.create({
      component: ProfileMenu,
      componentProps: { profiles: choices.value },
      event,
      side: 'bottom',
      alignment: 'start',
      cssClass: 'ic-profile-menu',
      // Ionic sets aria-modal: it needs a role (WCAG 4.1.2).
      htmlAttributes: { role: 'dialog', 'aria-label': t('profile.chooseActive') },
    });
    await popover.present();
    const { role, data } = await popover.onDidDismiss<{ id: string | null }>();
    if ('select' === role && data && data.id !== activeProfile.activeId) activeProfile.switchTo(data.id);
  }

  return { canSwitch, currentName, choices, choose };
}
