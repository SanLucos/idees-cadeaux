import { useIonRouter } from '@ionic/vue';
import { push } from '../services/push';
import { notificationsApi } from '../services/notifications';

/**
 * Spec §5.11 "écran d'explication avant la demande de permission OS":
 * callers show their explanation first, then call enable(). Consent is
 * recorded server-side (timestamped) only if the OS permission is
 * granted — or, in the browser, where no system prompt exists.
 */
export function usePushConsent() {
  const ionRouter = useIonRouter();

  /**
   * A tapped push opens the Activité centre: the push only carries the
   * notification's id and type, the centre has the rest (and the deep
   * link from there).
   */
  function onOpen(): void {
    ionRouter.push('/tabs/activity');
  }

  async function enable(): Promise<boolean> {
    const granted = push.isAvailable() ? await push.requestPermissionAndRegister(onOpen) : true;
    if (granted) await notificationsApi.updateSettings({ consents: { push: true } });

    return granted;
  }

  async function disable(): Promise<void> {
    await notificationsApi.updateSettings({ consents: { push: false } });
    await push.unregister();
  }

  return { enable, disable, onOpen };
}
