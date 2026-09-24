/** What another app shared with us (spec §5.6), once cleaned up. */
export interface SharedContent {
  url: string | null;
  title: string | null;
}

const URL_PATTERN = /https?:\/\/[^\s<>"]+/i;
const TRAILING_PUNCTUATION = /[).,;:!?»”'"]+$/;

/**
 * Browsers and apps share in many shapes: a bare URL, "Title https://…",
 * "Look at this! https://… via App", or a separate subject. Keeps the
 * first http(s) URL and a title: the subject if there is one, else the
 * text around the URL.
 */
export function parseSharedContent(input: { url?: string | null; text?: string | null; title?: string | null }): SharedContent | null {
  const candidates = [input.url, input.text].filter((s): s is string => !!s);
  let url: string | null = null;
  for (const candidate of candidates) {
    const match = candidate.match(URL_PATTERN);
    if (match) {
      url = match[0].replace(TRAILING_PUNCTUATION, '');
      break;
    }
  }

  const fromText = url && input.text ? input.text.replace(URL_PATTERN, ' ') : (input.text ?? '');
  const title = clean(input.title) ?? clean(fromText);
  if (!url && !title) return null;

  return { url, title };
}

function clean(value: string | null | undefined): string | null {
  const text = (value ?? '').replace(/\s+/g, ' ').trim().replace(/^[-–—:|\s]+|[-–—:|\s]+$/g, '');

  return text ? text.slice(0, 120) : null;
}

/** A readable stand-in title while the page can't be read (offline): the site's name. */
export function fallbackTitle(url: string): string {
  try {
    return new URL(url).hostname.replace(/^www\./, '');
  } catch {
    return url.slice(0, 120);
  }
}

/**
 * `<scheme>://share?url=…&text=…&title=…`, opened by the Android share
 * intent (MainActivity) or the iOS Share Extension. The scheme is the
 * app id, so it isn't checked here (CLAUDE.md §8: never hard-coded).
 */
export function parseShareDeepLink(link: string): SharedContent | null {
  let parsed: URL;
  try {
    parsed = new URL(link);
  } catch {
    return null;
  }
  const target = parsed.host || parsed.pathname.replace(/^\/+/, '');
  if ('share' !== target.replace(/\/+$/, '')) return null;

  return parseSharedContent({
    url: parsed.searchParams.get('url'),
    text: parsed.searchParams.get('text'),
    title: parsed.searchParams.get('title'),
  });
}
