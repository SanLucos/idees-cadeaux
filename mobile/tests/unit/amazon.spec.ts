import { describe, expect, it } from 'vitest';
import { isAmazonUrl } from '../../src/utils/amazon';

describe('isAmazonUrl', () => {
  it.each([
    ['https://amzn.eu/d/0e1uRtuY', true],
    ['https://a.co/d/abc', true],
    ['https://www.amazon.fr/dp/B0CFW29DZX', true],
    ['https://amazon.co.uk/Kettle/gp/product/B000000001', true],
    ['https://www.amazon.com.br/dp/B0CFW29DZX', true],
    ['https://amazon.fr.evil.example/dp/B0CFW29DZX', false],
    ['https://shop.example/amazon', false],
    ['pas un lien', false],
  ])('%s → %s', (url, expected) => {
    expect(isAmazonUrl(url)).toBe(expected);
  });
});
