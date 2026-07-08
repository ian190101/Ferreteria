import { assetUrl, updateFavicon } from './assets';

export function applyBranding(branding, { applyAppearance = true } = {}) {
    if (!branding) {
        return;
    }

    const primaryRgb = branding.primaryRgb || '37 99 235';
    const secondaryRgb = branding.secondaryRgb || '15 23 42';
    const primaryHex = branding.primary || '#2563eb';
    const secondaryHex = branding.secondary || '#0f172a';

    document.documentElement.style.setProperty('--color-primary', primaryRgb);
    document.documentElement.style.setProperty('--color-secondary', secondaryRgb);
    document.documentElement.style.setProperty('--brand-primary', primaryHex);
    document.documentElement.style.setProperty('--brand-secondary', secondaryHex);

    if (applyAppearance) {
        const savedMode = localStorage.getItem('appearance-mode');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        const useDark = savedMode === 'dark' || (!savedMode && (branding.themeMode === 'dark' || (branding.themeMode === 'system' && prefersDark)));

        document.documentElement.classList.toggle('dark', useDark);
        document.documentElement.classList.remove('liquid-glass');
        document.documentElement.style.colorScheme = useDark ? 'dark' : 'light';
        localStorage.removeItem('appearance-glass');
    }

    updateFavicon(assetUrl(branding.logoPath));
}
