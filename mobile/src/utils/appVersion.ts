/** « 0.0.3 · 42f3567 »: the version, and the commit it was built from when known. */
export function appVersionLabel(version: string = __APP_VERSION__, build: string = __APP_BUILD__): string {
  return build ? `${version} · ${build}` : version;
}
