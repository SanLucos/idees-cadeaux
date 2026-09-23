/**
 * "149 €", "29,90 €" — via Intl (spec §5.14). Whole amounts drop their
 * decimals, as on the mock-ups.
 */
export function formatPrice(amount: string | null, currency: string, locale: string): string | null {
  if (null === amount) return null;
  const value = Number(amount);
  if (Number.isNaN(value)) return null;

  return new Intl.NumberFormat(locale, {
    style: 'currency',
    currency,
    minimumFractionDigits: Number.isInteger(value) ? 0 : 2,
    maximumFractionDigits: 2,
  }).format(value);
}

/** Currencies offered in the idea form; the API accepts any ISO 4217 code. */
export const CURRENCIES = ['EUR', 'USD', 'GBP', 'CHF', 'CAD'];
