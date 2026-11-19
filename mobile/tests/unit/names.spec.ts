import { describe, expect, it } from 'vitest';
import { compareNames, sharedNameIds } from '../../src/utils/names';

describe('compareNames', () => {
  it('sorts pseudos alphabetically, ignoring accents and case', () => {
    const names = ['zoé', 'Élodie', 'anna', null, 'Bruno', 'Emma'];

    expect([...names].sort((a, b) => compareNames(a, b, 'fr'))).toEqual([null, 'anna', 'Bruno', 'Élodie', 'Emma', 'zoé']);
  });

  it('puts numbers in natural order', () => {
    expect(['Léo 10', 'Léo 2'].sort((a, b) => compareNames(a, b, 'fr'))).toEqual(['Léo 2', 'Léo 10']);
  });
});

describe('sharedNameIds', () => {
  it('finds the people sharing a pseudo, accents and case aside', () => {
    const people = [
      { id: 'a', name: 'Julie' },
      { id: 'b', name: 'Paul' },
      { id: 'c', name: 'julie' },
      { id: 'd', name: null },
      { id: 'e', name: null },
    ];

    expect([...sharedNameIds(people, 'fr')].sort()).toEqual(['a', 'c']);
  });
});
