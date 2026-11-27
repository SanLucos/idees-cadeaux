<template>
  <ion-content class="sidebar">
    <div class="sidebar__inner">
      <router-link to="/tabs/list" class="brand">
        <img :src="logo" alt="" width="40" height="40" />
        <span>{{ t('app.name') }}</span>
      </router-link>

      <ion-button class="new-idea" expand="block" router-link="/ideas/new">
        <ion-icon aria-hidden="true" slot="start" :icon="add" />
        {{ t('ideas.new') }}
      </ion-button>

      <nav :aria-label="t('desktop.mainNav')">
        <ion-list lines="none">
          <ion-item
            v-for="entry in entries"
            :key="entry.href"
            :router-link="entry.href"
            router-direction="root"
            :detail="false"
            class="entry"
            :class="{ 'entry--active': entry.active }"
            :aria-current="entry.active ? 'page' : undefined"
          >
            <ion-icon slot="start" :icon="entry.icon" aria-hidden="true" />
            <ion-label>{{ entry.label }}</ion-label>
            <ion-badge v-if="entry.badge" slot="end" :aria-label="entry.badgeLabel">{{ entry.badge }}</ion-badge>
            <span v-else-if="entry.count" slot="end" class="count" :aria-label="entry.badgeLabel">{{ entry.count }}</span>
          </ion-item>
        </ion-list>
      </nav>
    </div>
  </ion-content>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IonBadge, IonButton, IonContent, IonIcon, IonItem, IonLabel, IonList } from '@ionic/vue';
import { add, cubeOutline, giftOutline, notificationsOutline, peopleOutline, personOutline } from 'ionicons/icons';
import { useNotificationsStore } from '../stores/notifications';
import { useActiveProfileStore } from '../stores/activeProfile';
import { useLocalDb } from '../offline/db';
import { repo } from '../offline/repo';
import { giftsToOffer } from '../utils/myGifts';

/**
 * The computer layout's side navigation (spec §11 décision 72): the tab
 * bar's entries, « Nouvelle idée » in place of the « + » button, and
 * « Mes cadeaux » (décision 62) — hidden while acting as a child, like
 * its links in the pages.
 */
const { t } = useI18n();
const route = useRoute();
const notifications = useNotificationsStore();
const activeProfile = useActiveProfileStore();
const db = useLocalDb();

const logo = `${import.meta.env.BASE_URL}favicon.svg`;

const giftsCount = computed(() => (db.revision, giftsToOffer(repo.myGifts())));

const startsWith = (...prefixes: string[]) => prefixes.some((p) => route.path === p || route.path.startsWith(`${p}/`));

const entries = computed(() => {
  const unread = notifications.unreadCount;
  const list = [
    { href: '/tabs/list', icon: giftOutline, label: t('nav.myList'), active: startsWith('/tabs/list') },
    { href: '/tabs/friends', icon: peopleOutline, label: t('nav.friends'), active: startsWith('/tabs/friends') },
    {
      href: '/gifts',
      icon: cubeOutline,
      label: t('desktop.myGifts'),
      active: startsWith('/gifts'),
      count: giftsCount.value,
      badgeLabel: t('myGifts.toOffer', { count: giftsCount.value }, giftsCount.value),
      hidden: activeProfile.isActing,
    },
    {
      href: '/tabs/activity',
      icon: notificationsOutline,
      label: t('nav.activity'),
      active: startsWith('/tabs/activity'),
      badge: unread ? (unread > 99 ? '99+' : String(unread)) : '',
      badgeLabel: t('activity.unreadCount', { count: unread }),
    },
    { href: '/tabs/profile', icon: personOutline, label: t('nav.profile'), active: startsWith('/tabs/profile', '/profile', '/settings', '/admin') },
  ];

  return list.filter((entry) => !('hidden' in entry && entry.hidden));
});
</script>

<style scoped>
.sidebar {
  --background: var(--ic-surface);
  --padding-start: 0;
  --padding-end: 0;
}

.sidebar__inner {
  display: flex;
  flex-direction: column;
  gap: 24px;
  padding: 28px 20px;
}

.brand {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0 8px;
  color: var(--ion-text-color);
  text-decoration: none;
  font-family: var(--ic-font-display);
  font-size: 1.25rem;
}

.brand img {
  flex-shrink: 0;
  border-radius: 10px;
}

.new-idea {
  margin: 0;
  --box-shadow: 0 6px 16px rgba(var(--ion-color-primary-rgb), 0.25);
}

ion-list {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 0;
  background: transparent;
}

.entry {
  --background: transparent;
  --background-hover: var(--ic-surface-muted);
  --background-hover-opacity: 1;
  --border-radius: 12px;
  --min-height: 44px;
  --padding-start: 12px;
  --inner-padding-end: 12px;
  --color: var(--ion-text-color);
  font-size: 0.9375rem;
  font-weight: 600;
}

.entry ion-icon {
  margin-inline-end: 12px;
  font-size: 1.25rem;
  color: var(--ic-text-secondary);
}

.entry--active {
  --background: var(--ic-primary-soft);
  --color: var(--ic-primary-text-on-soft);
  font-weight: 700;
}

.entry--active ion-icon {
  color: inherit;
}

ion-badge {
  --background: var(--ion-color-primary);
  --color: var(--ion-color-primary-contrast);
  min-width: 22px;
  border-radius: 999px;
}

.count {
  font-size: 0.875rem;
  color: var(--ic-text-secondary);
}
</style>
