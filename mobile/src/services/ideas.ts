import { api } from './api';
import { uuidv7 } from '../utils/uuidv7';
import type { Idea, IdeaArchiveKind, IdeaInput, IdeaListQuery, IdeaPage, PrivateIdea } from '../types/idea';

function toQuery(query: IdeaListQuery): string {
  const params = new URLSearchParams();
  Object.entries(query).forEach(([key, value]) => {
    if (null !== value && undefined !== value && '' !== value) params.set(key, String(value));
  });
  const encoded = params.toString();

  return encoded ? `?${encoded}` : '';
}

/** Thin wrappers over the lot 3 endpoints (spec §7). */
export const ideasApi = {
  /** `userId` omitted: my own list (owner view, with segment counts). */
  list(query: IdeaListQuery, userId?: string): Promise<IdeaPage> {
    return api.get<IdeaPage>(`/users/${userId ?? 'me'}/ideas${toQuery(query)}`);
  },

  privateIdeas(): Promise<PrivateIdea[]> {
    return api.get<PrivateIdea[]>('/ideas/private');
  },

  get(id: string): Promise<Idea> {
    return api.get<Idea>(`/ideas/${id}`);
  },

  /** The id is generated here so a retried create stays a single idea (spec §8). */
  create(input: IdeaInput): Promise<Idea> {
    return api.post<Idea>('/ideas', { json: { id: uuidv7(), ...input } });
  },

  update(id: string, input: Partial<IdeaInput>): Promise<Idea> {
    return api.patch<Idea>(`/ideas/${id}`, { json: input });
  },

  remove(id: string): Promise<void> {
    return api.delete(`/ideas/${id}`);
  },

  publish(id: string): Promise<Idea> {
    return api.post<Idea>(`/ideas/${id}/publish`);
  },

  unpublish(id: string): Promise<Idea> {
    return api.post<Idea>(`/ideas/${id}/unpublish`);
  },

  archive(id: string, kind: IdeaArchiveKind): Promise<Idea> {
    return api.post<Idea>(`/ideas/${id}/archive`, { json: { kind } });
  },

  unarchive(id: string): Promise<Idea> {
    return api.post<Idea>(`/ideas/${id}/unarchive`);
  },

  uploadImage(id: string, file: File): Promise<Idea> {
    const formData = new FormData();
    formData.append('image', file);

    return api.post<Idea>(`/ideas/${id}/image`, { formData });
  },

  removeImage(id: string): Promise<Idea> {
    return api.delete<Idea>(`/ideas/${id}/image`);
  },
};
