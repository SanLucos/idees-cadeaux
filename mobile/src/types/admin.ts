/** The administration's types (spec §5.17, §11 décision 71). */

export type AdminRelation = 'friends' | 'introduced' | 'request_pending' | 'available' | 'unavailable';

export type AdminMode = 'introduce' | 'force';

export type AdminUserFilter = 'all' | 'regular' | 'managed' | 'deleting';

export interface AdminPerson {
  id: string;
  displayName: string | null;
  avatarUrl: string | null;
  type?: 'regular' | 'managed';
}

export interface AdminUser {
  id: string;
  displayName: string | null;
  avatarUrl: string | null;
  email: string | null;
  type: 'regular' | 'managed';
  managers: { id: string; displayName: string | null }[];
  emailVerified: boolean;
  deletionScheduledAt: string | null;
  isSuperAdmin: boolean;
  friendsCount: number;
  createdAt: string;
  /** How the account signs in: a password, an emailed link whose long session still runs (décision 68), Google, Apple. */
  signIn: { password: boolean; emailLink: boolean; social: ('google' | 'apple')[] };
  /** With `relativeTo`: where this user and that one stand. */
  relation: AdminRelation | null;
}

export interface AdminUserPage {
  total: number;
  page: number;
  pageSize: number;
  items: AdminUser[];
}

export interface AdminUserCard extends AdminUser {
  children: AdminPerson[];
  friends: { friendshipId: string; user: AdminPerson; origin: string; since: string | null }[];
  requests: { direction: 'incoming' | 'outgoing'; user: AdminPerson; createdAt: string }[];
  introductions: { id: string; user: AdminPerson; createdAt: string; expiresAt: string }[];
}
