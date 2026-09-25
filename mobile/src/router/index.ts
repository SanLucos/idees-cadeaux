import { createRouter, createWebHistory } from '@ionic/vue-router';
import { RouteRecordRaw } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { useActiveProfileStore } from '../stores/activeProfile';
import { useSharedContentStore } from '../stores/sharedContent';
import { usePendingInvitationStore } from '../stores/pendingInvitation';
import { parseSharedContent } from '../utils/sharedContent';

const queryString = (value: unknown) => (typeof value === 'string' ? value : null);

const routes: Array<RouteRecordRaw> = [
  { path: '/', redirect: '/tabs/list' },
  { path: '/login', name: 'Login', component: () => import('../views/auth/LoginPage.vue'), meta: { guest: true } },
  { path: '/register', name: 'Register', component: () => import('../views/auth/RegisterPage.vue'), meta: { guest: true } },
  {
    path: '/verify-email',
    name: 'VerifyEmail',
    component: () => import('../views/auth/VerifyEmailPage.vue'),
    meta: { guest: true },
  },
  {
    path: '/forgot-password',
    name: 'ForgotPassword',
    component: () => import('../views/auth/ForgotPasswordPage.vue'),
    meta: { guest: true },
  },
  {
    path: '/reset-password',
    name: 'ResetPassword',
    component: () => import('../views/auth/ResetPasswordPage.vue'),
    meta: { guest: true },
  },
  {
    path: '/onboarding',
    name: 'Onboarding',
    component: () => import('../views/OnboardingPage.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/tabs/',
    component: () => import('../views/TabsPage.vue'),
    meta: { requiresAuth: true, requiresOnboarding: true },
    children: [
      { path: '', redirect: '/tabs/list' },
      { path: 'list', name: 'MyList', component: () => import('../views/MyListPage.vue') },
      { path: 'list/private', name: 'PrivateIdeas', component: () => import('../views/PrivateIdeasPage.vue') },
      { path: 'friends', name: 'Friends', component: () => import('../views/FriendsPage.vue') },
      { path: 'friends/add', name: 'AddFriend', component: () => import('../views/AddFriendPage.vue') },
      { path: 'friends/:id', name: 'FriendList', component: () => import('../views/FriendListPage.vue') },
      { path: 'friends/:id/profile', name: 'FriendProfile', component: () => import('../views/FriendProfilePage.vue') },
      { path: 'friends/:id/archives', name: 'FriendArchives', component: () => import('../views/FriendArchivesPage.vue') },
      { path: 'activity', name: 'Activity', component: () => import('../views/ActivityPage.vue') },
      { path: 'profile', name: 'Profile', component: () => import('../views/ProfilePage.vue') },
    ],
  },
  // Share from another app (spec §5.6, services/shareIntake.ts): keep the
  // content, then open the form — through sign-in first if needed.
  {
    path: '/share',
    name: 'Share',
    component: () => import('../views/MyListPage.vue'),
    beforeEnter: (to) => {
      const content = parseSharedContent({ url: queryString(to.query.url), text: queryString(to.query.text), title: queryString(to.query.title) });
      if (content) useSharedContentStore().receive(content);

      return content ? { name: 'IdeaNew', query: { shared: '1' } } : { name: 'MyList' };
    },
  },
  // Account (spec §5.13): export, deletion, and the only screen left during its grace period.
  {
    path: '/settings/export',
    name: 'ExportData',
    component: () => import('../views/ExportDataPage.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/profile/children/:id/export',
    name: 'ChildExportData',
    component: () => import('../views/ExportDataPage.vue'),
    meta: { requiresAuth: true, requiresOnboarding: true },
  },
  {
    path: '/settings/delete-account',
    name: 'AccountDeletion',
    component: () => import('../views/AccountDeletionPage.vue'),
    meta: { requiresAuth: true, requiresOnboarding: true },
  },
  {
    path: '/account/deletion-scheduled',
    name: 'DeletionScheduled',
    component: () => import('../views/DeletionScheduledPage.vue'),
    meta: { requiresAuth: true },
  },
  // Share links (spec §5.16): the guest view for visitors without an
  // account (and the owner's preview), the confirmation screen once
  // signed in, and « J'ai un lien d'invitation ».
  {
    path: '/u/:token',
    name: 'GuestView',
    component: () => import('../views/GuestViewPage.vue'),
    beforeEnter: (to) => {
      const auth = useAuthStore();
      if (!auth.isAuthenticated || '1' === to.query.preview) return true;
      usePendingInvitationStore().keep(String(to.params.token));

      return auth.user?.isOnboarded ? { name: 'JoinViaLink', params: { token: to.params.token } } : { name: 'Onboarding' };
    },
  },
  {
    path: '/join/:token',
    name: 'JoinViaLink',
    component: () => import('../views/JoinViaLinkPage.vue'),
    meta: { requiresAuth: true, requiresOnboarding: true },
  },
  { path: '/open-link', name: 'OpenShareLink', component: () => import('../views/OpenShareLinkPage.vue') },
  {
    path: '/profile/share',
    name: 'ShareProfile',
    component: () => import('../views/ShareProfilePage.vue'),
    meta: { requiresAuth: true, requiresOnboarding: true },
  },
  {
    path: '/profile/children/:id/share',
    name: 'ChildShareProfile',
    component: () => import('../views/ShareProfilePage.vue'),
    meta: { requiresAuth: true, requiresOnboarding: true },
  },
  // Full-screen pages, outside the tab bar (as on the FicheIdee and NouvelleIdee mock-ups).
  {
    path: '/ideas/new',
    name: 'IdeaNew',
    component: () => import('../views/IdeaFormPage.vue'),
    meta: { requiresAuth: true, requiresOnboarding: true },
  },
  {
    path: '/ideas/:id',
    name: 'IdeaDetail',
    component: () => import('../views/IdeaDetailPage.vue'),
    meta: { requiresAuth: true, requiresOnboarding: true },
  },
  {
    path: '/ideas/:id/edit',
    name: 'IdeaEdit',
    component: () => import('../views/IdeaFormPage.vue'),
    meta: { requiresAuth: true, requiresOnboarding: true },
  },
  {
    path: '/contributions/:id',
    name: 'Contribution',
    component: () => import('../views/ContributionPage.vue'),
    meta: { requiresAuth: true, requiresOnboarding: true },
  },
  {
    path: '/profile/children/new',
    name: 'ChildNew',
    component: () => import('../views/ChildFormPage.vue'),
    meta: { requiresAuth: true, requiresOnboarding: true },
  },
  {
    path: '/profile/children/:id',
    name: 'Child',
    component: () => import('../views/ChildPage.vue'),
    meta: { requiresAuth: true, requiresOnboarding: true },
  },
  {
    path: '/invitation',
    name: 'Invitation',
    component: () => import('../views/auth/InvitationPage.vue'),
    meta: { guest: true },
  },
  {
    path: '/settings/notifications',
    name: 'NotificationSettings',
    component: () => import('../views/NotificationSettingsPage.vue'),
    meta: { requiresAuth: true, requiresOnboarding: true },
  },
  {
    path: '/onboarding/notifications',
    name: 'NotificationConsent',
    component: () => import('../views/NotificationConsentPage.vue'),
    meta: { requiresAuth: true, requiresOnboarding: true },
  },
  {
    path: '/profile/edit',
    name: 'ProfileEdit',
    component: () => import('../views/ProfileEditPage.vue'),
    meta: { requiresAuth: true, requiresOnboarding: true },
  },
];

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
});

let childrenLoaded = false;

router.beforeEach(async (to) => {
  const auth = useAuthStore();
  if (auth.isBootstrapping) {
    await auth.bootstrap();
  }

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return { name: 'Login' };
  }
  // Spec §5.13: an account being deleted only sees « Votre compte sera supprimé le … ».
  if (auth.user?.deletionScheduledAt) {
    return ['DeletionScheduled', 'ExportData'].includes(String(to.name)) ? true : { name: 'DeletionScheduled' };
  }
  if ('DeletionScheduled' === to.name) {
    return { name: 'MyList' };
  }
  if (to.meta.guest && auth.isAuthenticated) {
    return { name: 'MyList' };
  }
  if (to.meta.requiresOnboarding && auth.isAuthenticated && !auth.user?.isOnboarded) {
    return { name: 'Onboarding' };
  }
  if ('Onboarding' === to.name && auth.user?.isOnboarded) {
    return { name: 'MyList' };
  }
  // A share received while signed out (spec §5.6): open it once signed in and onboarded.
  if (auth.user?.isOnboarded && useSharedContentStore().pending && 'IdeaNew' !== to.name) {
    return { name: 'IdeaNew', query: { shared: '1' } };
  }

  // A share link opened signed out or offline (spec §5.16): its
  // confirmation screen, once signed in and onboarded.
  const pendingInvitation = usePendingInvitationStore();
  const invitation = pendingInvitation.deferred ? null : pendingInvitation.token;
  if (auth.user?.isOnboarded && invitation && !['JoinViaLink', 'GuestView', 'NotificationConsent', 'IdeaNew'].includes(String(to.name))) {
    return { name: 'JoinViaLink', params: { token: invitation } };
  }

  // Restore the active child profile before any screen loads its data,
  // so the first request already carries the right X-Acting-As.
  const activeProfile = useActiveProfileStore();
  if (auth.isAuthenticated && auth.user?.isOnboarded && !childrenLoaded) {
    childrenLoaded = true;
    await activeProfile.fetchChildren().catch(() => activeProfile.switchTo(null));
  }
  if (!auth.isAuthenticated) {
    childrenLoaded = false;
  }

  return true;
});

export default router;
