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
