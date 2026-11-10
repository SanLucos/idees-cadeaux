/**
 * Amazon refuses to serve its pages to our server (spec §5.5): the form
 * says so instead of a generic "couldn't read the page". Same hosts as
 * the server's AmazonPage::isAmazon: the stores and their short links.
 */
export function isAmazonUrl(url: string): boolean {
  let host: string;
  try {
    host = new URL(url.trim()).hostname.toLowerCase();
  } catch {
    return false;
  }

  return /(?:^|\.)amazon\.(?:co\.|com\.)?[a-z]{2,3}$/.test(host) || /^(?:www\.)?(?:amzn\.(?:eu|to|com|asia)|a\.co)$/.test(host);
}
