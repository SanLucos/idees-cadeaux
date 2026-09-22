import { Contacts } from '@capacitor-community/contacts';

/** Thrown when the OS contacts permission is denied (spec §5.3). */
export class ContactsPermissionDeniedError extends Error {}

async function sha256Hex(text: string): Promise<string> {
  const data = new TextEncoder().encode(text);
  const digest = await crypto.subtle.digest('SHA-256', data);

  return Array.from(new Uint8Array(digest))
    .map((byte) => byte.toString(16).padStart(2, '0'))
    .join('');
}

/**
 * Reads the device's contacts, keeps only normalized emails, and
 * hashes them (SHA-256) before they ever leave the device — spec
 * §5.3: "le téléphone envoie des emails normalisés et hachés,
 * aucun contact n'est stocké côté serveur." Raw contact data (names,
 * phone numbers, unhashed emails) never reaches services/api.ts.
 */
export async function collectHashedContactEmails(): Promise<string[]> {
  const permission = await Contacts.requestPermissions();
  if ('granted' !== permission.contacts) {
    throw new ContactsPermissionDeniedError();
  }

  const { contacts } = await Contacts.getContacts({ projection: { emails: true } });

  const normalizedEmails = new Set<string>();
  for (const contact of contacts) {
    for (const email of contact.emails ?? []) {
      if (email.address) {
        normalizedEmails.add(email.address.toLowerCase().trim());
      }
    }
  }

  return Promise.all([...normalizedEmails].map(sha256Hex));
}
