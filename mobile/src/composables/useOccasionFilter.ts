import { computed, onMounted, type Ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useOccasionsStore } from '../stores/occasions';
import { useIdeaLabels } from './useIdeaLabels';
import { countBy, NO_OCCASION, occasionKey } from '../utils/ideaTable';
import type { FilterOption } from '../components/FilterChipGroup.vue';
import type { Idea } from '../types/idea';

/**
 * « Occasion » line of a table's filters (spec §11 décision 72): « Toutes »,
 * then each occasion present in `ideas` (in the reference order, « Sans
 * occasion » last) with its count. One at a time; picking it again clears it.
 */
export function useOccasionFilter(ideas: () => Idea[], selected: Ref<string | null>) {
  const { t } = useI18n();
  const store = useOccasionsStore();
  const labels = useIdeaLabels();

  onMounted(() => void store.ensureLoaded().catch(() => undefined));

  const options = computed<FilterOption[]>(() => {
    const counts = countBy(ideas(), occasionKey);
    const known = store.occasions.map((o) => o.code);
    // An occasion missing from the reference list still shows (its code).
    const codes = [...new Set([...known, ...Object.keys(counts).filter((c) => NO_OCCASION !== c), NO_OCCASION])].filter(
      (code) => counts[code] || selected.value === code,
    );

    return [
      { value: '', label: t('ideas.allOccasions'), pressed: null === selected.value },
      ...codes.map((code) => ({
        value: code,
        label: t('desktop.occasionCount', {
          label: labels.occasionLabel({ occasion: NO_OCCASION === code ? null : code } as Idea),
          count: counts[code] ?? 0,
        }),
        pressed: selected.value === code,
      })),
    ];
  });

  function pick(value: string): void {
    selected.value = '' === value || selected.value === value ? null : value;
  }

  return { options, pick };
}
