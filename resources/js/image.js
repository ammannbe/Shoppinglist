// Product photos are scaled down on the device before the upload: small files for slow
// mobile connections and the offline cache, and the re-encoding drops the EXIF data
// (location!) of camera photos.

const MAX_SIZE = 480;
const QUALITY = 0.75;

/**
 * @param {File} file photo from the camera or the library
 * @returns {Promise<Blob>} WebP, or JPEG where the browser cannot encode WebP (Safari)
 */
export async function shrinkImage(file) {
    const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
    const scale = Math.min(1, MAX_SIZE / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(bitmap.width * scale);
    canvas.height = Math.round(bitmap.height * scale);
    canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close();

    for (const type of ['image/webp', 'image/jpeg']) {
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, type, QUALITY));
        if (blob?.type === type) return blob;
    }
    throw new Error('Image encoding failed');
}
