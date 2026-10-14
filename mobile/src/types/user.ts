export interface User {
  id: string;
  email: string | null;
  displayName: string | null;
  avatarUrl: string | null;
  birthDay: number | null;
  birthMonth: number | null;
  birthYear: number | null;
  locale: string;
  isOnboarded: boolean;
  emailVerified: boolean;
  type?: 'regular' | 'managed';
  managedBy?: { id: string; displayName: string | null } | null;
  /** Spec §5.13: set during the 14 days of grace before the account is erased. */
  deletionScheduledAt?: string | null;
  /** Re-authentication: the password, or else a code sent by email. */
  hasPassword?: boolean;
}

/** A child profile (spec §5.15), as listed by GET /managed-profiles. */
export interface ManagedProfile {
  id: string;
  displayName: string | null;
  avatarUrl: string | null;
  birthDay: number | null;
  birthMonth: number | null;
  birthYear: number | null;
  managedBy: { id: string; displayName: string | null } | null;
  ideaCount: number;
  parentalConsentAt: string | null;
  deletionScheduledAt: string | null;
  invitation: { email: string; expiresAt: string } | null;
  /** « Partager avec mes amis » (spec §11 décision 59). */
  shareWithManagerFriends: boolean;
  /** One or two, same rights (spec §11 décision 60). */
  managers: ManagerSummary[];
  /** The second manager invited, until they answer or it expires. */
  managerInvitation: { user: { id: string; displayName: string | null }; expiresAt: string } | null;
}

export interface ManagerSummary {
  id: string;
  displayName: string | null;
  avatarUrl: string | null;
}

/** An invitation to manage someone's child with them (spec §11 décision 60). */
export interface ManagerInvitation {
  id: string;
  expiresAt: string;
  invitedBy: { id: string; displayName: string | null };
  child: { id: string; displayName: string | null; avatarUrl: string | null };
}
