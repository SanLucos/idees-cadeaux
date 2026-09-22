/**
 * Google/Apple sign-in (spec §5.1). The spec itself flags the native
 * plugin choice as still open ("plugins Capacitor à choisir et
 * valider", spec §2) — it needs real Google Cloud / Apple Developer
 * OAuth client credentials before it can be wired to an actual
 * plugin. The backend side (POST /api/auth/social/{provider}, ID
 * token verification) is implemented and ready; only this native call
 * is a placeholder.
 */
export async function getGoogleIdToken(): Promise<string> {
  throw new Error('google-sign-in-not-configured');
}

export async function getAppleIdToken(): Promise<string> {
  throw new Error('apple-sign-in-not-configured');
}
