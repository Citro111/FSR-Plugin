(() => {
    'use strict';

    const config = window.FSREtitLinkMarker;
    if (!config || !config.endpoint) {
        return;
    }

    const normalizeUrl = (href) => {
        try {
            return new URL(href, window.location.href).href;
        } catch (error) {
            return '';
        }
    };

    const normalizedOldUrls = Array.isArray(config.oldUrls)
        ? config.oldUrls.map((url) => normalizeUrl(url)).filter(Boolean)
        : [];

    const isOldUrl = (url) => {
        try {
            const target = new URL(url);

            return normalizedOldUrls.some((baseUrl) => {
                const base = new URL(baseUrl);
                const basePath = base.pathname.endsWith('/') ? base.pathname : `${base.pathname}/`;
                const targetPath = target.pathname.endsWith('/') ? target.pathname : `${target.pathname}/`;

                if (target.hostname.toLowerCase() !== base.hostname.toLowerCase()) {
                    return false;
                }

                if (target.port !== base.port) {
                    return false;
                }

                const current = new URL(window.location.origin + (config.sitePath || '/'));
                const currentPath = current.pathname.endsWith('/') ? current.pathname : `${current.pathname}/`;
                if (
                    base.hostname.toLowerCase() === current.hostname.toLowerCase()
                    && base.port === current.port
                    && basePath === currentPath
                ) {
                    return false;
                }

                return basePath === '/' || targetPath.startsWith(basePath);
            });
        } catch (error) {
            return false;
        }
    };

    const isCurrentSiteUrl = (url) => {
        try {
            const target = new URL(url);
            return target.hostname.toLowerCase() === String(config.siteHost || '').toLowerCase();
        } catch (error) {
            return false;
        }
    };

    const browserProbeCache = new Map();

    /*
     * Browser probes must be side-effect free. In particular, never probe
     * wp-login.php?action=logout&_wpnonce=... or other action URLs from the
     * WordPress admin bar. Even a HEAD request reaches PHP and can execute
     * those actions.
     */
    const isSafeBrowserProbeUrl = (url) => {
        try {
            const target = new URL(url, window.location.href);

            // Only the exact origin currently displayed in the browser.
            if (target.origin !== window.location.origin) {
                return false;
            }

            // Query strings commonly carry actions/nonces. Do not execute them.
            if (target.search !== '') {
                return false;
            }

            const path = target.pathname.toLowerCase();
            if (
                /\/wp-admin(?:\/|$)/.test(path)
                || /\/wp-login\.php$/.test(path)
                || /\/wp-json(?:\/|$)/.test(path)
                || /\/wp-cron\.php$/.test(path)
                || /\/xmlrpc\.php$/.test(path)
            ) {
                return false;
            }

            return true;
        } catch (error) {
            return false;
        }
    };

    const probeInternalUrlInBrowser = (url) => {
        if (!isCurrentSiteUrl(url) || !isSafeBrowserProbeUrl(url)) {
            return Promise.resolve({ status: 'unknown', url, reason: 'unsafe-to-probe' });
        }

        if (browserProbeCache.has(url)) {
            return browserProbeCache.get(url);
        }

        const probe = (async () => {
            try {
                const response = await fetch(url, {
                    method: 'HEAD',
                    credentials: 'same-origin',
                    // Never follow a redirect into a login/action endpoint.
                    redirect: 'manual',
                    cache: 'no-store'
                });

                if (response.status === 404) {
                    return { status: 'missing', url, http: 404 };
                }

                // No GET fallback: GET may execute application actions.
                if (response.status === 405 || response.status === 501) {
                    return { status: 'unknown', url, http: response.status, reason: 'head-not-supported' };
                }

                // Manual redirects may be exposed as an opaque redirect (status 0).
                if (response.type === 'opaqueredirect' || response.status === 0) {
                    return { status: 'ok', url, reason: 'redirect' };
                }

                return { status: 'ok', url, http: response.status };
            } catch (error) {
                return { status: 'unknown', url };
            }
        })();

        browserProbeCache.set(url, probe);
        return probe;
    };

    const isSkippable = (anchor, url) => {
        if (!anchor || !url) {
            return true;
        }

        if (anchor.dataset.fsrLinkMarkerProcessed === '1') {
            return true;
        }

        if (
            anchor.hasAttribute('download')
            || anchor.matches('[contenteditable="true"]')
            || anchor.closest('#wpadminbar')
            || anchor.closest('[data-fsr-link-marker-ignore]')
        ) {
            return true;
        }

        const parsed = new URL(url);

        if (!['http:', 'https:'].includes(parsed.protocol)) {
            return true;
        }

        return false;
    };

    const collectLinks = () => {
        const anchors = Array.from(document.querySelectorAll('a[href]'));
        const links = [];

        for (const anchor of anchors) {
            const url = normalizeUrl(anchor.getAttribute('href'));

            if (isSkippable(anchor, url)) {
                continue;
            }

            const parsed = new URL(url);
            const siteHost = String(config.siteHost || '').toLowerCase();
            const host = parsed.hostname.toLowerCase();

            const isCurrentSite = host === siteHost;
            const isOldSite = isOldUrl(url);

            if (!isCurrentSite && !isOldSite) {
                continue;
            }

            links.push({ anchor, url });
            anchor.dataset.fsrLinkMarkerProcessed = '1';
        }

        return links;
    };

    const markAnchor = (anchor, status) => {
        if (!status || !status.status) {
            return;
        }

        if (status.status === 'missing' && config.showMissingForCurrentUser) {
            anchor.classList.add('fsr-link-marker--missing');
            anchor.setAttribute('data-fsr-link-marker', 'missing');
        }

        if (status.status === 'empty' && config.showEmptyForCurrentUser) {
            anchor.classList.add('fsr-link-marker--empty');
            anchor.setAttribute('data-fsr-link-marker', 'empty');
        }

        if (status.status === 'old' && config.showOldForCurrentUser) {
            anchor.classList.add('fsr-link-marker--old');
            anchor.setAttribute('data-fsr-link-marker', 'old');
        }
    };

    const requestBatch = async (items) => {
        const response = await fetch(config.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                urls: items.map(item => item.url)
            })
        });

        if (!response.ok) {
            throw new Error(`FSR link marker request failed: ${response.status}`);
        }

        return response.json();
    };

    const run = async () => {
        const links = collectLinks();

        if (!links.length) {
            return;
        }

        const batchSize = Number(config.batchSize) > 0
            ? Number(config.batchSize)
            : 50;

        for (let start = 0; start < links.length; start += batchSize) {
            const batch = links.slice(start, start + batchSize);

            try {
                const data = await requestBatch(batch);
                const statuses = data && data.statuses ? data.statuses : {};

                await Promise.all(batch.map(async (item) => {
                    let status = statuses[item.url];

                    if (status && status.status === 'unknown' && isCurrentSiteUrl(item.url)) {
                        status = await probeInternalUrlInBrowser(item.url);
                    }

                    markAnchor(item.anchor, status);
                }));
            } catch (error) {
                /*
                 * A failed diagnostic request must never interfere with normal
                 * navigation or page rendering.
                 */
                console.warn('FSR ET/IT Link Marker:', error);
            }
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run, { once: true });
    } else {
        run();
    }
})();
