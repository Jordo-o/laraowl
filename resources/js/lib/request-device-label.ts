export function requestDeviceLabel(headers: unknown): string | null {
    if (typeof headers === 'string') {
        try {
            headers = JSON.parse(headers);
        } catch {
            return null;
        }
    }

    if (!headers || typeof headers !== 'object') {
        return null;
    }

    const header = Object.entries(headers).find(
        ([name]) => name.toLowerCase() === 'user-agent',
    )?.[1];
    const value = Array.isArray(header) ? header[0] : header;

    if (typeof value !== 'string' || !value.trim()) {
        return null;
    }

    const ua = value.trim();
    const automated = /bot\b|crawler|spider|headlesschrome/i.test(ua);
    const browser = [
        [/Edg(?:A|iOS)?\//i, 'Edge'],
        [/OPR\/|Opera\//i, 'Opera'],
        [/SamsungBrowser\//i, 'Samsung Internet'],
        [/Firefox\/|FxiOS\//i, 'Firefox'],
        [/Chrome\/|CriOS\//i, 'Chrome'],
        [/Version\/.*Safari\//i, 'Safari'],
        [/^curl\//i, 'curl'],
        [/^PostmanRuntime\//i, 'Postman'],
    ] as const;
    const client = browser.find(([pattern]) => pattern.test(ua))?.[1];

    if (automated) {
        return 'Bot / crawler';
    }

    const platform = [
        [/(?:Android TV|SmartTV|SMART-TV|GoogleTV|HbbTV)/i, 'TV'],
        [/iPad|Macintosh.*Mobile\//i, 'iPad'],
        [/iPhone|iPod/i, 'iPhone / iPod'],
        [/Android.*Mobile/i, 'Android phone'],
        [/Android/i, 'Android tablet'],
        [/Windows/i, 'Windows'],
        [/CrOS/i, 'ChromeOS'],
        [/Macintosh/i, 'macOS'],
        [/Linux/i, 'Linux'],
    ] as const;
    const device = platform.find(([pattern]) => pattern.test(ua))?.[1];
    const label = [device, client].filter(Boolean).join(' · ');

    return label || null;
}
