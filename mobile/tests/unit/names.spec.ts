import { describe, expect, it } from 'vitest';
import { compareNames } from '../../src/utils/names';

describe('compareNames', () => {
  it('sorts pseudos alphabetically, ignoring accents and case', () => {
    const names = ['zoé', 'Élodie', 'anna', null, 'Bruno', 'Emma'];

    expect([...names].sort((a, b) => compareNames(a, b, 'fr'))).toEqual([null, 'anna', 'Bruno', 'Élodie', 'Emma', 'zoé']);
  });

  it('puts numbers in natural order', () => {
    expect(['Léo 10', 'Léo 2'].sort((a, b) => compareNames(a, b, 'fr'))).toEqual(['Léo 2', 'Léo 10']);
  });
});
