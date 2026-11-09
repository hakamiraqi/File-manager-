// Reads a scanned or photographed bubble sheet in the browser: the picture onto a canvas,
// then the core (corners, perspective, bubbles), with jsQR for the sheet's QR code.
import jsQR from 'jsqr';
import { readPixels } from './omrCore';

export function readSheet(image, questions) {
    const scale = Math.min(1, 1800 / Math.max(image.naturalWidth, image.naturalHeight));
    const w = Math.round(image.naturalWidth * scale), h = Math.round(image.naturalHeight * scale);
    const canvas = document.createElement('canvas');
    canvas.width = w; canvas.height = h;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(image, 0, 0, w, h);
    const img = ctx.getImageData(0, 0, w, h);
    return readPixels(img, questions, (crop, full) => (jsQR(crop.data, crop.width, crop.height) || jsQR(full.data, full.width, full.height))?.data ?? null);
}
