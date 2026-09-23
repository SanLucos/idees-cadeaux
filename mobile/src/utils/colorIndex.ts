/** Stable palette index from an id (same id → same colour on every screen and device). */
export function colorIndex(id: string, paletteSize: number): number {
  let hash = 0;
  for (let i = 0; i < id.length; i++) {
    hash = (hash * 31 + id.charCodeAt(i)) >>> 0;
  }

  return hash % paletteSize;
}

/** "Luc" → "Lu", "Marc Dupont" → "MD": two letters max, as on the mock-ups. */
export function initials(name: string | null | undefined): string {
  const trimmed = (name ?? '').trim();
  if ('' === trimmed) return '?';
  const words = trimmed.split(/\s+/);
  if (words.length > 1) return (words[0][0] + words[1][0]).toUpperCase();

  return words[0].slice(0, 1).toUpperCase();
}
