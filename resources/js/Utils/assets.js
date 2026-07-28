export function assetUrl(path) {
    if (!path) {
        return null;
    }

    let value = String(path).trim();

    const imgSrc = value.match(/<img[^>]+src=["']([^"']+)["']/i)?.[1];

    if (imgSrc) {
        value = imgSrc.trim();
    }

    const googleDriveFile = value.match(/^https:\/\/drive\.google\.com\/file\/d\/([^/]+)\/view/i);

    if (googleDriveFile) {
        return `https://drive.google.com/thumbnail?id=${googleDriveFile[1]}&sz=w512`;
    }

    if (/^https:\/\//i.test(value) || /^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?\//i.test(value)) {
        return value;
    }

    if (/^data:image\/(png|jpeg|jpg|webp|gif);base64,[A-Za-z0-9+/=]+$/i.test(value)) {
        return value;
    }

    return value.startsWith('/') ? value : `/${value}`;
}

export function updateFavicon(logoSrc) {
    if (!logoSrc) {
        return;
    }

    let favicon = document.querySelector('link[rel="icon"]');

    if (!favicon) {
        favicon = document.createElement('link');
        favicon.rel = 'icon';
        document.head.appendChild(favicon);
    }

    favicon.href = logoSrc;
}
