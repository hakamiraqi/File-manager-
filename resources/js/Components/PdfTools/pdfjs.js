// pdf.js for the PDF tools' page pictures (organise, watermark preview).
import * as pdfjs from 'pdfjs-dist/legacy/build/pdf.mjs';
import workerUrl from 'pdfjs-dist/legacy/build/pdf.worker.min.mjs?url';

pdfjs.GlobalWorkerOptions.workerSrc = workerUrl;

export function openPdf(url) {
    return pdfjs.getDocument({ url, withCredentials: true });
}

/** One page as a canvas `width` CSS pixels wide (sharp on high-density screens). */
export async function pageCanvas(doc, n, width, rotation = 0) {
    const page = await doc.getPage(n);
    const turn = (page.rotate + rotation) % 360;
    const base = page.getViewport({ scale: 1, rotation: turn });
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    const viewport = page.getViewport({ scale: (width / base.width) * dpr, rotation: turn });
    const canvas = document.createElement('canvas');
    canvas.width = Math.floor(viewport.width);
    canvas.height = Math.floor(viewport.height);
    canvas.style.width = '100%';
    canvas.style.height = 'auto';
    canvas.style.display = 'block';
    await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
    return canvas;
}

export async function pageSize(doc, n) {
    const page = await doc.getPage(n);
    const v = page.getViewport({ scale: 1 });
    return { w: v.width, h: v.height };
}
