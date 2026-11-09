/** Number of background tints for child profiles (src/theme/variables.css, `data-profile-tint`). */
export const PROFILE_TINT_COUNT = 5;

/**
 * The app background's tint for the active profile: null for my own
 * account (the usual cream), otherwise the child's position among my
 * children, so two children never share a tint (up to five).
 */
export function profileTint(activeId: string | null, childIds: string[]): number | null {
  if (null === activeId) return null;
  const index = childIds.indexOf(activeId);

  return -1 === index ? null : index % PROFILE_TINT_COUNT;
}

/** Applied on <html> so every screen, tabs or not, follows the active profile. */
export function applyProfileTint(tint: number | null, root: HTMLElement = document.documentElement): void {
  if (null === tint) delete root.dataset.profileTint;
  else root.dataset.profileTint = String(tint);
}
