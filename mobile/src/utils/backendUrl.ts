const apiBase = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api';

/** A page served by the backend outside the API (privacy policy…): its origin, without `/api`. */
export function backendUrl(path: string): string {
  return `${apiBase.replace(/\/api\/?$/, '')}${path}`;
}
