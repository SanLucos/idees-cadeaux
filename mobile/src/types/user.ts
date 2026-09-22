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
}
