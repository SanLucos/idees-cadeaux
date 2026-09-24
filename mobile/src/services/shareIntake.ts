import { App } from '@capacitor/app';
import { Capacitor } from '@capacitor/core';
import type { Router } from 'vue-router';
import { parseShareDeepLink } from '../utils/sharedContent';

/**
 * Shares from other apps (spec §5.6) arrive as a `<app id>://share?…`
 * link: Android's MainActivity rewrites the ACTION_SEND intent into one,
 * the iOS Share Extension opens one. Both land on the /share route,
 * which stores the content and opens the idea form.
 */
export async function listenForShares(router: Router): Promise<void> {
  if (!Capacitor.isNativePlatform()) return;

  const open = (link: string | undefined) => {
    if (!link || !parseShareDeepLink(link)) return;
    const query = Object.fromEntries(new URL(link).searchParams.entries());
    void router.push({ path: '/share', query });
  };

  await App.addListener('appUrlOpen', ({ url }) => open(url));
  open((await App.getLaunchUrl())?.url);
}
