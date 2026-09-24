import { api } from './api';

/** What POST /link-previews proposes (spec §5.5): every field may be null. */
export interface LinkPreview {
  url: string;
  title: string | null;
  priceAmount: string | null;
  priceCurrency: string | null;
  /** Already resized and re-encoded by the server; uploaded only if the user keeps it. */
  imageDataUrl: string | null;
}

export const linkPreviewApi = {
  fetch(url: string): Promise<LinkPreview> {
    return api.post<LinkPreview>('/link-previews', { json: { url } });
  },
};

/** The image as a File, for the ordinary upload (online or from the outbox). */
export async function previewImageFile(dataUrl: string): Promise<File> {
  const blob = await (await fetch(dataUrl)).blob();

  return new File([blob], 'link-preview.jpg', { type: blob.type || 'image/jpeg' });
}
