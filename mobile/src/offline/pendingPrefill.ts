import { ApiError, isNetworkError } from '../services/api';
import { linkPreviewApi, previewImageFile } from '../services/linkPreview';
import { ideasApi } from '../services/ideas';
import { useActiveProfileStore } from '../stores/activeProfile';
import { useLocalDb } from './db';
import type { Idea, IdeaInput } from '../types/idea';

const KEY = 'prefill.pending';

/** An idea saved offline from a link, whose pre-fill waits for the network (spec §5.6). */
export interface PendingPrefill {
  ideaId: string;
  url: string;
  /** The stand-in title it was saved with: replaced only if the user left it as is. */
  fallbackTitle: string;
  /** The profile it was created as (X-Acting-As): completed only from that profile. */
  actingAs: string | null;
}

function read(): PendingPrefill[] {
  try {
    return JSON.parse(useLocalDb().meta[KEY] ?? '[]') as PendingPrefill[];
  } catch {
    return [];
  }
}

async function write(entries: PendingPrefill[]): Promise<void> {
  await useLocalDb().setMeta(KEY, entries.length ? JSON.stringify(entries) : null);
}

export async function addPendingPrefill(entry: PendingPrefill): Promise<void> {
  await write([...read().filter((e) => e.ideaId !== entry.ideaId), entry]);
}

let running = false;

/**
 * Back online: fetch each preview and fill in only what the user left
 * empty — values are proposed, never imposed (spec §5.5). Whatever
 * happens, an entry is tried until the page answers or is found
 * unreadable; a network error keeps it for the next time.
 */
export async function completePendingPrefills(): Promise<void> {
  if (running || !read().length) return;
  running = true;
  try {
    const activeId = useActiveProfileStore().activeId;
    for (const entry of read()) {
      if (entry.actingAs !== activeId) continue;
      const idea = useLocalDb().get<Idea>('idea', entry.ideaId);
      if (!idea || idea.url !== entry.url) {
        await remove(entry.ideaId);
        continue;
      }

      try {
        const preview = await linkPreviewApi.fetch(entry.url);
        await apply(idea, entry, preview);
      } catch (e) {
        if (isNetworkError(e)) return;
        if (!(e instanceof ApiError) || 429 === e.status || e.status >= 500) return;
      }
      await remove(entry.ideaId);
    }
  } finally {
    running = false;
  }
}

async function apply(idea: Idea, entry: PendingPrefill, preview: Awaited<ReturnType<typeof linkPreviewApi.fetch>>): Promise<void> {
  const patch: Partial<IdeaInput> = {};
  if (preview.title && idea.title === entry.fallbackTitle) patch.title = preview.title;
  if (preview.priceAmount && !idea.priceAmount) {
    patch.priceAmount = preview.priceAmount;
    patch.priceCurrency = preview.priceCurrency ?? idea.priceCurrency;
  }
  if (Object.keys(patch).length) await ideasApi.update(idea.id, patch);
  if (preview.imageDataUrl && !idea.imageUrl) await ideasApi.uploadImage(idea.id, await previewImageFile(preview.imageDataUrl));
}

async function remove(ideaId: string): Promise<void> {
  await write(read().filter((e) => e.ideaId !== ideaId));
}
