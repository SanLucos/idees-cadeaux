import { useI18n } from 'vue-i18n';
import { actionSheetController, alertController, toastController, useIonRouter } from '@ionic/vue';
import { ideasApi } from '../services/ideas';
import { useErrorMessage } from './useErrorMessage';
import type { Idea } from '../types/idea';

export type IdeaChange = { type: 'updated'; idea: Idea } | { type: 'deleted'; id: string };

/**
 * Every action a user may take on an idea (spec §5.4), offered only
 * when the server says so (`canEdit`, `canUnarchive`, `view`,
 * `isMine`) — the client never decides visibility or rights itself.
 */
export function useIdeaActions(onChange: (change: IdeaChange) => void) {
  const { t } = useI18n();
  const ionRouter = useIonRouter();
  const { describe } = useErrorMessage();

  async function run(action: () => Promise<IdeaChange>): Promise<void> {
    try {
      onChange(await action());
    } catch (e) {
      const toast = await toastController.create({ message: describe(e), duration: 3000, color: 'danger' });
      await toast.present();
    }
  }

  async function confirm(header: string, message: string, confirmText: string, destructive = false): Promise<boolean> {
    const alert = await alertController.create({
      header,
      message,
      buttons: [
        { text: t('common.cancel'), role: 'cancel' },
        { text: confirmText, role: destructive ? 'destructive' : 'confirm' },
      ],
    });
    await alert.present();

    return 'cancel' !== (await alert.onDidDismiss()).role;
  }

  const isOwnerView = (idea: Idea) => 'owner' === idea.view;
  // Author, reserver or contribution initiator (spec §5.4): the server decides.
  const canMarkGifted = (idea: Idea) => !isOwnerView(idea) && !!idea.canMarkGifted;

  function publish(idea: Idea): Promise<void> {
    return run(async () => ({ type: 'updated', idea: await ideasApi.publish(idea.id) }));
  }

  /**
   * Spec §5.4: the owner only ever gets a generic warning (règle d'or:
   * no count, no detail); the suggestion's author sees the detail of
   * what will go, from the hidden fields the API sent them.
   */
  function authorWarning(idea: Idea): string {
    const lost: string[] = [];
    if (idea.reservation) lost.push(t('ideas.unpublishConfirm.lostReservation', { name: idea.reservation.user.displayName }));
    if ('open' === idea.contribution?.status) {
      lost.push(t('ideas.unpublishConfirm.lostContribution', { count: idea.contribution.participantCount }, idea.contribution.participantCount));
    }
    if (idea.commentCount) lost.push(t('ideas.unpublishConfirm.lostComments', { count: idea.commentCount }, idea.commentCount));
    if (idea.reactions?.count) lost.push(t('ideas.unpublishConfirm.lostLikes', { count: idea.reactions.count }, idea.reactions.count));

    return lost.length ? t('ideas.unpublishConfirm.authorDetail', { items: lost.join(', ') }) : t('ideas.unpublishConfirm.authorNothing');
  }

  async function unpublish(idea: Idea): Promise<void> {
    const message = isOwnerView(idea) ? t('ideas.unpublishConfirm.owner') : authorWarning(idea);
    if (!(await confirm(t('ideas.unpublishConfirm.title'), message, t('ideas.actions.unpublish')))) return;

    await run(async () => ({ type: 'updated', idea: await ideasApi.unpublish(idea.id) }));
  }

  async function remove(idea: Idea): Promise<void> {
    if (!(await confirm(t('ideas.deleteConfirm.title'), t('ideas.deleteConfirm.message', { title: idea.title }), t('common.delete'), true))) {
      return;
    }

    await run(async () => {
      await ideasApi.remove(idea.id);

      return { type: 'deleted', id: idea.id };
    });
  }

  function archive(idea: Idea): Promise<void> {
    const kind = isOwnerView(idea) ? 'received' : 'gifted';

    return run(async () => ({ type: 'updated', idea: await ideasApi.archive(idea.id, kind) }));
  }

  function unarchive(idea: Idea): Promise<void> {
    return run(async () => ({ type: 'updated', idea: await ideasApi.unarchive(idea.id) }));
  }

  async function openSheet(idea: Idea): Promise<void> {
    const buttons: { text: string; role?: string; handler?: () => void }[] = [];

    if (idea.canEdit) {
      buttons.push({ text: t('ideas.actions.edit'), handler: () => ionRouter.push(`/ideas/${idea.id}/edit`) });
      buttons.push(
        'private' === idea.visibility
          ? { text: t('ideas.actions.publish'), handler: () => void publish(idea) }
          : { text: t('ideas.actions.unpublish'), handler: () => void unpublish(idea) },
      );
    }
    if (isOwnerView(idea) && 'active' === idea.status) {
      buttons.push({ text: t('ideas.actions.markReceived'), handler: () => void archive(idea) });
    }
    if (canMarkGifted(idea)) {
      buttons.push({ text: t('ideas.actions.markGifted'), handler: () => void archive(idea) });
    }
    if (idea.canUnarchive) {
      buttons.push({ text: t('ideas.actions.unarchive'), handler: () => void unarchive(idea) });
    }
    if (idea.canEdit) {
      buttons.push({ text: t('common.delete'), role: 'destructive', handler: () => void remove(idea) });
    }
    buttons.push({ text: t('common.cancel'), role: 'cancel' });

    const sheet = await actionSheetController.create({ header: idea.title, buttons });
    await sheet.present();
  }

  return { openSheet, publish, unpublish, remove, archive, unarchive, canMarkGifted };
}
