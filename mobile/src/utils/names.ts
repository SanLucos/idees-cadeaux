/** « Luc », « Luc et Chloé », « Luc, Chloé et Anna » in the user's language. */
export function joinNames(names: (string | null | undefined)[], locale: string): string {
  const list = names.filter((n): n is string => !!n);

  return new Intl.ListFormat(locale, { style: 'long', type: 'conjunction' }).format(list);
}

/** Alphabetical order of pseudos in the user's language: accents and case ignored, numbers in order. */
export function compareNames(a: string | null | undefined, b: string | null | undefined, locale: string): number {
  return (a ?? '').localeCompare(b ?? '', locale, { sensitivity: 'base', numeric: true });
}

/** Ids of the people whose pseudo another one shares (same comparison as the sort), to tell them apart. */
export function sharedNameIds(people: { id: string; name: string | null | undefined }[], locale: string): Set<string> {
  const ids = new Set<string>();
  people.forEach((a, i) => {
    for (const b of people.slice(i + 1)) {
      if (a.name && b.name && 0 === compareNames(a.name, b.name, locale)) ids.add(a.id).add(b.id);
    }
  });

  return ids;
}
