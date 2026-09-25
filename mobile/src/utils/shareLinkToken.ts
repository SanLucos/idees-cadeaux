const TOKEN = /^[A-Za-z0-9_-]{22}$/;

/**
 * The token of a share link (spec §5.16), from whatever the user pasted
 * or the system opened: `https://<domaine>/u/<token>`, the app's own
 * `<app id>://u/<token>`, or the bare token. Null when it isn't one.
 * The domain and scheme aren't checked: both are configuration
 * (CLAUDE.md §8), and the server validates the token anyway.
 */
export function parseShareLinkToken(input: string | null | undefined): string | null {
  const text = (input ?? '').trim();
  if (TOKEN.test(text)) return text;

  const match = text.match(/(?:^|[/:])u\/([A-Za-z0-9_-]{22})(?=$|[/?#\s])/);

  return match ? match[1] : null;
}
