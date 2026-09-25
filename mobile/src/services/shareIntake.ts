import { App } from '@capacitor/app';
import { Capacitor } from '@capacitor/core';
import { Network } from '@capacitor/network';
import type { Router } from 'vue-router';
import { parseShareDeepLink } from '../utils/sharedContent';
import { parseShareLinkToken } from '../utils/shareLinkToken';
import { usePendingInvitationStore } from '../stores/pendingInvitation';

/**
 * Links the system opens the app with:
 *
 * - shares from other apps (spec §5.6) arrive as `<app id>://share?…`:
 *   Android's MainActivity rewrites the ACTION_SEND intent into one,
 *   the iOS Share Extension opens one. Both land on the /share route,
 *   which stores the content and opens the idea form;
 * - share links (spec §5.16), `https://<domaine>/u/<token>` (App Links,
 *   Universal Links) or `<app id>://u/<token>` (the web page's « Ouvrir
 *   dans l'appli »), land on /u/:token: guest view, or confirmation.
 */
export async function listenForShares(router: Router): Promise<void> {
  // A share link put aside offline is reopened when the network is back.
  await Network.addListener('networkStatusChange', ({ connected }) => {
    const pending = usePendingInvitationStore();
    if (connected && pending.deferred && pending.token) {
      pending.resume();
      void router.push({ name: 'JoinViaLink', params: { token: pending.token } });
    }
  });

  if (!Capacitor.isNativePlatform()) return;

  // Android reports a cold-start link twice (launch URL and appUrlOpen).
  let last = { link: '', at: 0 };
  const open = (link: string | undefined) => {
    if (!link || (link === last.link && Date.now() - last.at < 5000)) return;

    if (parseShareDeepLink(link)) {
      last = { link, at: Date.now() };
      const query = Object.fromEntries(new URL(link).searchParams.entries());
      void router.push({ path: '/share', query });
      return;
    }

    const token = parseShareLinkToken(link);
    if (token) {
      last = { link, at: Date.now() };
      void router.push({ name: 'GuestView', params: { token } });
    }
  };

  await App.addListener('appUrlOpen', ({ url }) => open(url));
  open((await App.getLaunchUrl())?.url);
}
