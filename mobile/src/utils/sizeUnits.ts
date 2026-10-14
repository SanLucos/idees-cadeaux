/** The units a size can carry (spec §5.2, §11 décision 57); null: no unit, the default. */
export const SIZE_UNITS = ['cm', 'mm', 'm', 'g', 'kg', 'ml', 'l', 'eu', 'us', 'uk'] as const;

export type SizeUnit = (typeof SIZE_UNITS)[number];

/** "80 cm", "42 EU", or the value alone; `unitLabel` gives the translated unit. */
export function formatSize(value: string, unit: SizeUnit | null | undefined, unitLabel: (unit: SizeUnit) => string): string {
  return unit ? `${value} ${unitLabel(unit)}` : value;
}
