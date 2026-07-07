export function assetUrl(path) {
    if (!path) {
        return null;
    }

    const value = String(path).trim();

    if (/^(https?:)?\/\//i.test(value) || value.startsWith('data:')) {
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
