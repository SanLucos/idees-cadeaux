/** « Luc », « Luc et Chloé », « Luc, Chloé et Anna » in the user's language. */
export function joinNames(names: (string | null | undefined)[], locale: string): string {
  const list = names.filter((n): n is string => !!n);

  return new Intl.ListFormat(locale, { style: 'long', type: 'conjunction' }).format(list);
}

/** Alphabetical order of pseudos in the user's language: accents and case ignored, numbers in order. */
export function compareNames(a: string | null | undefined, b: string | null | undefined, locale: string): number {
  return (a ?? '').localeCompare(b ?? '', locale, { sensitivity: 'base', numeric: true });
}
