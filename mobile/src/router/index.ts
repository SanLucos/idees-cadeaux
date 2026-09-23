import { createRouter, createWebHistory } from '@ionic/vue-router';
import { RouteRecordRaw } from 'vue-router';
import { useAuthStore } from '../stores/auth';

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
      { path: 'list', name: 'MyList', component: () => import('../views/HomePage.vue') },
      { path: 'friends', name: 'Friends', component: () => import('../views/FriendsPage.vue') },
      { path: 'friends/add', name: 'AddFriend', component: () => import('../views/AddFriendPage.vue') },
      { path: 'friends/:id', name: 'FriendProfile', component: () => import('../views/FriendProfilePage.vue') },
      { path: 'activity', name: 'Activity', component: () => import('../views/ActivityPage.vue') },
      { path: 'profile', name: 'Profile', component: () => import('../views/ProfilePage.vue') },
    ],
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

router.beforeEach(async (to) => {
  const auth = useAuthStore();
  if (auth.isBootstrapping) {
    await auth.bootstrap();
  }

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return { name: 'Login' };
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

  return true;
});

export default router;
