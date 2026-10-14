import { Capacitor } from '@capacitor/core';

/**
 * In a browser — the web app, main entry point (spec §11 décision 56) —
 * rather than the iOS/Android app. The web app keeps no local copy, holds
 * its session in an httpOnly cookie and hides what only a phone can do
 * (contacts, push, shares from other apps).
 */
export function isWeb(): boolean {
  return !Capacitor.isNativePlatform();
}
