/**
 * RFC 9562 UUID v7: 48-bit ms timestamp + random bits. Generated on the
 * device so an idea keeps the same id from an offline create to its
 * sync (CLAUDE.md règle 9).
 */
export function uuidv7(now: number = Date.now()): string {
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);

  let timestamp = now;
  for (let i = 5; i >= 0; i--) {
    bytes[i] = timestamp % 256;
    timestamp = Math.floor(timestamp / 256);
  }
  bytes[6] = (bytes[6] & 0x0f) | 0x70; // version 7
  bytes[8] = (bytes[8] & 0x3f) | 0x80; // RFC variant

  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');

  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
