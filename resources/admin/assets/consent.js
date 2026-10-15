// The cookie consent of the analytics counters and advertising pixels (Plans 40 and 41). The theme helper consentHead()
// prints this module with the CSP nonce, the services' ids and their script URLs in data attributes (the URLs come from
// the server, so this file names no host); the theme draws the banner: a [data-consent] element rendered hidden with
// [data-consent-accept] and [data-consent-decline] buttons inside and, with advertising pixels, a
// [data-consent-choose] button that opens the [data-consent-panel] (rendered hidden) of the categories: a checkbox
// [data-consent-category="statistics|advertising"] each and a [data-consent-save] button. [data-consent-open] controls
// anywhere (the footer's "Cookie settings") reopen it.
//
// Two categories: "statistics" (Yandex Metrica, Google Analytics) and "advertising" (Meta Pixel, Google Ads, VK Ads,
// TikTok Pixel); the site offers those with a configured service. The visitor's choice is the first-party cookie
// atar_consent, a token per decided category ("s1.a0": statistics granted, advertising denied; Plan 40's "1"/"0" are
// both at once), 180 days. While an offered category is undecided the banner shows; it never takes the focus by itself
// and traps nothing (a region, not a modal). Each category's scripts load only after it is granted: their <script>
// elements get this module's nonce (the property; browsers hide the attribute) and are initialised here, never by
// inline code. Google's tag (gtag.js) is loaded once for GA4 and Google Ads, with Consent Mode v2 signals per category.
// Withdrawing a category stops what has a stop call and deletes its services' first-party cookies; Metrica and the VK
// counter have none, so they run until the visitor leaves the page and are never loaded again.

const COOKIE = 'atar_consent';
const MAX_AGE = 15552000; // 180 days
const LETTERS = {statistics: 's', advertising: 'a'};
const loader = document.querySelector('script[data-atar-consent]');
const banner = document.querySelector('[data-consent]');
const panel = banner?.querySelector('[data-consent-panel]') ?? null;
const chooser = banner?.querySelector('[data-consent-choose]') ?? null;
const data = loader?.dataset ?? {};
// The categories this site offers: those with a configured service.
const offered = [
    ...(data.metrica || data.ga ? ['statistics'] : []),
    ...(data.meta || data.googleAds || data.vk || data.tiktok ? ['advertising'] : []),
];
// The services' first-party cookies and storage keys, by category: Metrica's _ym_* and mdd, Google Analytics' _ga, _ga_*,
// _gid, _gat*; Meta's _fbp and _fbc, Google Ads' _gcl_*, TikTok's _ttp, _tt_enable_cookie and ttcsid*, VK's tmr_* and
// domain_sid.
const COOKIES = {
    statistics: /^(?:_ym|_ga|_gid|_gat|mdd$)/,
    advertising: /^(?:_fbp$|_fbc$|_gcl_|_ttp$|_tt_enable_cookie$|ttcsid|tmr_|domain_sid$)/,
};
const STORAGE = {statistics: '_ym', advertising: 'tmr_'};
const loaded = {statistics: false, advertising: false};
let tagged = false;
let opener = null;

// The stored choice: {statistics, advertising}, each true, false or undefined (not decided).
function stored() {
    const pair = document.cookie.split('; ').find((cookie) => cookie.startsWith(COOKIE + '='));
    const value = pair?.slice(COOKIE.length + 1);
    if (value === '1' || value === '0') {
        // Plan 40's choice covered its statistics-only banner: advertising is asked anew.
        return {statistics: value === '1'};
    }
    const match = /^(?:s([01])(?:\.a([01]))?|a([01]))$/.exec(value ?? '');
    if (match === null) {
        return {};
    }
    const flag = (digit) => (digit === undefined ? undefined : digit === '1');

    return {statistics: flag(match[1]), advertising: flag(match[2] ?? match[3])};
}

function store(choice) {
    const value = Object.keys(LETTERS).filter((category) => choice[category] !== undefined).map((category) => LETTERS[category] + (choice[category] ? '1' : '0')).join('.');
    // SameSite=None on https: Metrica's click map and Webvisor's player show the site in a frame on metrika.yandex.ru,
    // where a Lax cookie is not sent (the banner would show and nothing would load). Browsers refuse None without
    // Secure, so http keeps Lax. The cookie holds only the choice.
    document.cookie = COOKIE + '=' + value + '; Max-Age=' + MAX_AGE + '; Path=/; SameSite=' + (location.protocol === 'https:' ? 'None; Secure' : 'Lax');
}

function add(src, id) {
    const script = document.createElement('script');
    script.nonce = loader.nonce;
    script.async = true;
    script.src = src;
    if (id) {
        script.id = id;
    }
    document.head.append(script);
}

// Google Consent Mode v2: analytics_storage follows statistics, the three ad signals follow advertising.
function signals(choice) {
    const ads = choice.advertising === true ? 'granted' : 'denied';

    return {analytics_storage: choice.statistics === true ? 'granted' : 'denied', ad_storage: ads, ad_user_data: ads, ad_personalization: ads};
}

// gtag.js once for GA4 and Google Ads: the first id allowed names the script, the other is configured on the same tag.
// Later choices update its signals in apply().
function tag(id, choice) {
    if (tagged) {
        return;
    }
    tagged = true;
    window.dataLayer = window.dataLayer || [];
    // gtag.js reads Arguments objects from the data layer, as Google's snippet pushes them.
    window.gtag = function () {
        window.dataLayer.push(arguments);
    };
    window.gtag('consent', 'default', signals(choice));
    window.gtag('js', new Date());
    add(data.gtagSrc + id);
}

function loadStatistics(choice) {
    const {metrica, metricaSrc, webvisor, ga, gtagSrc} = data;
    if (loaded.statistics) {
        // Allowed again after a withdrawal on this page: Google Analytics is here already and counts again.
        if (ga) {
            window['ga-disable-' + ga] = false;
        }

        return;
    }
    loaded.statistics = true;
    if (metrica && metricaSrc) {
        // Yandex's queue: calls made before tag.js arrives wait in ym.a.
        window.ym = window.ym || function () {
            (window.ym.a = window.ym.a || []).push(arguments);
        };
        window.ym.l = Date.now();
        window.ym(Number(metrica), 'init', {clickmap: true, trackLinks: true, accurateTrackBounce: true, webvisor: webvisor !== undefined});
        add(metricaSrc);
    }
    if (ga && gtagSrc) {
        window['ga-disable-' + ga] = false;
        tag(ga, choice);
        window.gtag('config', ga);
    }
}

function loadAdvertising(choice) {
    const {meta, metaSrc, googleAds, gtagSrc, vk, vkSrc, tiktok, tiktokSrc} = data;
    if (loaded.advertising) {
        // Allowed again after a withdrawal on this page: the pixels here already resume.
        window.fbq?.('consent', 'grant');
        window.ttq?.grantConsent?.();

        return;
    }
    loaded.advertising = true;
    if (meta && metaSrc) {
        // Meta's queue (its snippet's fbq): calls made before fbevents.js arrives wait in fbq.queue.
        if (!window.fbq) {
            const fbq = function () {
                if (fbq.callMethod) {
                    fbq.callMethod.apply(fbq, arguments);
                } else {
                    fbq.queue.push(arguments);
                }
            };
            window.fbq = fbq;
            window._fbq = window._fbq || fbq;
            fbq.push = fbq;
            fbq.loaded = true;
            fbq.version = '2.0';
            fbq.queue = [];
        }
        window.fbq('init', meta);
        window.fbq('track', 'PageView');
        add(metaSrc);
    }
    if (googleAds && gtagSrc) {
        tag(googleAds, choice);
        window.gtag('config', googleAds);
    }
    if (vk && vkSrc) {
        // VK's (Top@Mail.ru) queue: code.js reads _tmr when it arrives; its id keeps a second copy out.
        const tmr = window._tmr || (window._tmr = []);
        tmr.push({id: vk, type: 'pageView', start: Date.now()});
        add(vkSrc, 'tmr-code');
    }
    if (tiktok && tiktokSrc) {
        // TikTok's queue (its snippet's ttq): the methods called before events.js arrives wait in ttq.
        window.TiktokAnalyticsObject = 'ttq';
        const ttq = window.ttq = window.ttq || [];
        ttq.methods = ['page', 'track', 'identify', 'instances', 'debug', 'on', 'off', 'once', 'ready', 'alias', 'group', 'enableCookie', 'disableCookie', 'holdConsent', 'revokeConsent', 'grantConsent'];
        ttq.setAndDefer = function (target, method) {
            target[method] = function () {
                target.push([method].concat(Array.prototype.slice.call(arguments, 0)));
            };
        };
        for (const method of ttq.methods) {
            ttq.setAndDefer(ttq, method);
        }
        ttq.instance = function (id) {
            const instance = ttq._i[id] || [];
            for (const method of ttq.methods) {
                ttq.setAndDefer(instance, method);
            }

            return instance;
        };
        ttq.load = function (id, options) {
            ttq._i = ttq._i || {};
            ttq._i[id] = [];
            ttq._i[id]._u = tiktokSrc;
            ttq._t = ttq._t || {};
            ttq._t[id] = Date.now();
            ttq._o = ttq._o || {};
            ttq._o[id] = options || {};
            add(tiktokSrc + '?sdkid=' + id + '&lib=ttq');
        };
        ttq.load(tiktok);
        ttq.page();
    }
}

// Withdrawal: the stop calls, then the category's first-party cookies on this host and each parent domain and its
// storage keys. Cookies of the services' own domains (Yandex's, Google's, Meta's, TikTok's, VK's) are out of reach.
function forget(category) {
    if (category === 'statistics') {
        const ga = data.ga;
        if (ga) {
            window['ga-disable-' + ga] = true;
        }
    } else {
        window.fbq?.('consent', 'revoke');
        window.ttq?.revokeConsent?.();
    }
    const names = document.cookie.split('; ').map((cookie) => cookie.split('=')[0]).filter((name) => COOKIES[category].test(name));
    const labels = location.hostname.split('.');
    const domains = [''];
    for (let index = 0; index < labels.length - 1; index++) {
        domains.push('; Domain=' + labels.slice(index).join('.'));
    }
    for (const name of names) {
        for (const domain of domains) {
            document.cookie = name + '=; Max-Age=0; Path=/' + domain;
        }
    }
    try {
        for (const key of Object.keys(localStorage)) {
            if (key.startsWith(STORAGE[category])) {
                localStorage.removeItem(key);
            }
        }
    } catch {
        // Storage may be off (a private window): nothing was stored there either.
    }
}

// Each offered category as chosen: granted loads it, denied withdraws it, undecided waits. Google's tag, once here, first
// gets every signal of the choice (analytics_storage and the ad signals; GA4 alone uses the latter for its advertising
// features), so a later config or a withdrawal never runs on stale ones.
function apply(choice) {
    if (tagged) {
        window.gtag('consent', 'update', signals(choice));
    }
    for (const category of offered) {
        if (choice[category] === true) {
            (category === 'statistics' ? loadStatistics : loadAdvertising)(choice);
        } else if (choice[category] === false) {
            forget(category);
        }
    }
}

// The categories' panel: open or closed; open, its switches show the stored choice (unchecked while undecided).
function expand(open) {
    if (panel === null) {
        return;
    }
    if (open) {
        const choice = stored();
        for (const input of panel.querySelectorAll('[data-consent-category]')) {
            input.checked = choice[input.dataset.consentCategory] === true;
        }
    }
    panel.hidden = !open;
    if (chooser !== null) {
        chooser.ariaExpanded = open ? 'true' : 'false';
    }
}

function choose(choice) {
    const current = {...stored(), ...choice};
    store(current);
    apply(current);
    if (banner !== null) {
        const inside = banner.contains(document.activeElement);
        banner.hidden = true;
        expand(false);
        // Reopened from a "Cookie settings" control: the focus goes back there.
        if (opener !== null && inside) {
            opener.focus();
        }
    }
    opener = null;
}

// "Accept all" or "Decline": every offered category at once.
function all(granted) {
    return Object.fromEntries(offered.map((category) => [category, granted]));
}

document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (target === null) {
        return;
    }
    if (target.closest('[data-consent-accept]')) {
        choose(all(true));
    } else if (target.closest('[data-consent-decline]')) {
        choose(all(false));
    } else if (target.closest('[data-consent-choose]')) {
        expand(panel !== null && panel.hidden);
    } else if (target.closest('[data-consent-save]') && panel !== null) {
        const choice = {};
        for (const input of panel.querySelectorAll('[data-consent-category]')) {
            if (offered.includes(input.dataset.consentCategory)) {
                choice[input.dataset.consentCategory] = input.checked;
            }
        }
        choose(choice);
    } else if (target.closest('[data-consent-open]') && banner !== null) {
        event.preventDefault();
        opener = target.closest('[data-consent-open]');
        banner.hidden = false;
        // With a stored choice the panel opens on it, so the visitor sees what is allowed.
        const state = stored();
        expand(offered.some((category) => state[category] !== undefined));
        banner.focus();
    }
});

const state = stored();
apply(state);
if (banner !== null && offered.some((category) => state[category] === undefined)) {
    banner.hidden = false;
}
