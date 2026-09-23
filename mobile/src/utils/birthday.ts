function isLeapYear(year: number): boolean {
  return (year % 4 === 0 && year % 100 !== 0) || year % 400 === 0;
}

/**
 * Days from `today` to the next birthday (0 = today). A 29 February
 * birthday is celebrated on 28 February in non-leap years (spec §5.11).
 */
export function daysUntilBirthday(day: number, month: number, today: Date = new Date()): number {
  const start = new Date(today.getFullYear(), today.getMonth(), today.getDate());

  const occurrence = (year: number): Date => {
    const effectiveDay = 2 === month && 29 === day && !isLeapYear(year) ? 28 : day;

    return new Date(year, month - 1, effectiveDay);
  };

  let next = occurrence(start.getFullYear());
  if (next < start) {
    next = occurrence(start.getFullYear() + 1);
  }

  return Math.round((next.getTime() - start.getTime()) / 86_400_000);
}

/** "4 octobre" / "October 4", via Intl (spec §5.14: no hand-rolled date formats). */
export function formatBirthday(day: number, month: number, locale: string): string {
  return new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'long' }).format(new Date(2000, month - 1, day));
}

/** Threshold under which the "J-x" pill is shown on the friends list (J-14 is the default first reminder, spec §5.11). */
export const UPCOMING_BIRTHDAY_DAYS = 14;
