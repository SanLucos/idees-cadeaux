import { api } from './api';
import { repo } from '../offline/repo';
import { ideaMutations } from '../offline/mutations';
import { useAuthStore } from '../stores/auth';
import { useActiveProfileStore } from '../stores/activeProfile';
import { fileToDataUrl } from '../utils/fileToDataUrl';
import type { Idea, IdeaArchiveKind, IdeaInput, IdeaListQuery, IdeaPage, PrivateIdea } from '../types/idea';

function selfId(): string {
  return useActiveProfileStore().activeId ?? useAuthStore().user?.id ?? '';
}

/**
 * Ideas, read from the device (spec §8) and written through the offline
 * outbox. Lists that aren't synced (a child's friends' lists, spec §11
 * décision 33) are still read from the API, online.
 */
export const ideasApi = {
  /** `userId` omitted: my own list (or the active child's). */
  async list(query: IdeaListQuery, userId?: string): Promise<IdeaPage> {
    const ownerId = userId ?? selfId();
    if (repo.hasList(ownerId, selfId())) return repo.ideas(ownerId, query);

    const params = new URLSearchParams();
    Object.entries(query).forEach(([key, value]) => {
      if (null !== value && undefined !== value && '' !== value) params.set(key, String(value));
    });

    return api.get<IdeaPage>(`/users/${ownerId}/ideas?${params}`);
  },

  async privateIdeas(): Promise<PrivateIdea[]> {
    return repo.privateIdeas(useAuthStore().user?.id ?? '', useActiveProfileStore().activeId);
  },

  async get(id: string): Promise<Idea> {
    return repo.idea(id) ?? api.get<Idea>(`/ideas/${id}`);
  },

  create(input: IdeaInput): Promise<Idea> {
    return ideaMutations.create(input);
  },

  update(id: string, input: Partial<IdeaInput>): Promise<Idea> {
    return ideaMutations.update(id, input);
  },

  remove(id: string): Promise<void> {
    return ideaMutations.remove(id);
  },

  publish(id: string): Promise<Idea> {
    return ideaMutations.publish(id);
  },

  unpublish(id: string): Promise<Idea> {
    return ideaMutations.unpublish(id);
  },

  archive(id: string, kind: IdeaArchiveKind): Promise<Idea> {
    return ideaMutations.archive(id, kind);
  },

  unarchive(id: string): Promise<Idea> {
    return ideaMutations.unarchive(id);
  },

  async uploadImage(id: string, file: File): Promise<Idea> {
    return ideaMutations.setImage(id, { dataUrl: await fileToDataUrl(file), filename: file.name || 'image.jpg' });
  },

  removeImage(id: string): Promise<Idea> {
    return ideaMutations.removeImage(id);
  },
};
