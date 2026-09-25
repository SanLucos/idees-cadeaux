import { api } from './api';
import type { User } from '../types/user';

/** `{password}` or `{code}` (spec §5.13 ré-authentification). */
export type Reauth = { password: string } | { code: string };

/** Spec §5.13, always as the signed-in adult (never X-Acting-As). */
const OWN = { acting: false } as const;

export const accountApi = {
  sendReauthCode(): Promise<void> {
    return api.post('/account/reauth-code', OWN);
  },
  requestDeletion(reauth: Reauth): Promise<User> {
    return api.post<User>('/account/deletion', { json: reauth, ...OWN });
  },
  cancelDeletion(): Promise<User> {
    return api.post<User>('/account/cancel-deletion', OWN);
  },
  /** My data, or a child profile's (link sent to me). */
  requestExport(reauth: Reauth, childId?: string | null): Promise<{ id: string; status: string }> {
    return api.post(childId ? `/managed-profiles/${childId}/export` : '/account/export', { json: reauth, ...OWN });
  },
};
