// Product photos are scaled down on the device before the upload: small files for slow
// mobile connections and the offline cache, and the re-encoding drops the EXIF data
// (location!) of camera photos. Two sizes: the rows only load the small one, the
// full view (lightbox) the large one.

const SIZES = { image: { max: 1200, quality: 0.8 }, thumb: { max: 240, quality: 0.75 } };

/**
 * @param {File} file photo from the camera or the library
 * @returns {Promise<{ image: Blob, thumb: Blob }>} WebP, or JPEG where the browser cannot encode WebP (Safari)
 */
export async function shrinkImage(file) {
    const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
    try {
        const result = {};
        for (const [name, { max, quality }] of Object.entries(SIZES)) {
            result[name] = await encode(bitmap, max, quality);
        }

        return result;
    } finally {
        bitmap.close();
    }
}

/**
 * Icon of an own shop: max. 64 px, small enough to be synced inside the shop document.
 *
 * @param {File} file
 * @returns {Promise<string>} data URL (WebP, or JPEG/PNG where the browser cannot encode WebP)
 */
export async function shrinkIcon(file) {
    const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
    try {
        const blob = await encode(bitmap, 64, 0.85, ['image/webp', 'image/png']);

        return await new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.onerror = () => reject(reader.error);
            reader.readAsDataURL(blob);
        });
    } finally {
        bitmap.close();
    }
}

/**
 * Photos for the AI (offers, pantry): large enough to read small print, but no 12 MP
 * originals over the mobile network. PDFs and other files are sent unchanged.
 *
 * @param {File} file
 * @returns {Promise<Blob|File>}
 */
export async function prepareForAi(file) {
    if (!file.type.startsWith('image/')) return file;
    try {
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        try {
            return await encode(bitmap, 2048, 0.85, ['image/jpeg']);
        } finally {
            bitmap.close();
        }
    } catch {
        // Formats the browser cannot decode (e.g. HEIC on some devices): the server decides.
        return file;
    }
}

async function encode(bitmap, max, quality, types = ['image/webp', 'image/jpeg']) {
    const scale = Math.min(1, max / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(bitmap.width * scale);
    canvas.height = Math.round(bitmap.height * scale);
    const context = canvas.getContext('2d');
    context.imageSmoothingQuality = 'high';
    context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);

    for (const type of types) {
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, type, quality));
        if (blob?.type === type) return blob;
    }
    throw new Error('Image encoding failed');
}
