/** « Luc », « Luc et Chloé », « Luc, Chloé et Anna » in the user's language. */
export function joinNames(names: (string | null | undefined)[], locale: string): string {
  const list = names.filter((n): n is string => !!n);

  return new Intl.ListFormat(locale, { style: 'long', type: 'conjunction' }).format(list);
}
