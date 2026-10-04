import jsQR from 'jsqr';

// Camera and album imports share the same decoder; callers retain their own epoch guards.
export async function readQrImage(file) {
    if (!file || !/^image\//.test(file.type) || file.size > 10000000) throw new Error('Choose a QR image smaller than 10 MB.');
    let url;
    try {
        url = URL.createObjectURL(file);
        const image = new Image();
        await new Promise((resolve, reject) => {
            image.onload = resolve;
            image.onerror = () => reject(new Error('Unable to read this image.'));
            image.src = url;
        });
        if (!image.width || !image.height) throw new Error('Unable to read this image.');
        const scale = Math.min(1, 1600 / Math.max(image.width, image.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(image.width * scale);
        canvas.height = Math.round(image.height * scale);
        const context = canvas.getContext('2d');
        context.drawImage(image, 0, 0, canvas.width, canvas.height);
        const pixels = context.getImageData(0, 0, canvas.width, canvas.height);
        const code = jsQR(pixels.data, pixels.width, pixels.height, {inversionAttempts: 'attemptBoth'});
        if (!code?.data) throw new Error('No QR code found. Try a clearer image.');
        return code.data;
    } finally {
        if (url) URL.revokeObjectURL(url);
    }
}
