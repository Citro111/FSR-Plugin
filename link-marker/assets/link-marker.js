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

    const isSkippable = (anchor, url) => {
        if (!anchor || !url) {
            return true;
        }

        if (anchor.dataset.fsrLinkMarkerProcessed === '1') {
            return true;
        }

        if (
            anchor.target === '_blank'
            || anchor.hasAttribute('download')
            || anchor.matches('[contenteditable="true"]')
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
            const oldHost = String(config.oldHost || '').toLowerCase();
            const host = parsed.hostname.toLowerCase();

            const isCurrentSite = host === siteHost;
            const isOldSite = host === oldHost && host !== siteHost;

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

                for (const item of batch) {
                    markAnchor(item.anchor, statuses[item.url]);
                }
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
