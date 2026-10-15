// The YouTube field's card (fields/youtube.php): the server renders it for the stored video; here it follows the
// input. A pause in typing reads the video id from the input (an id, or a link of a shape VideoUrl::parse() accepts)
// and shows its cover and YouTube link (addresses from the widget's data attributes), or hides the card; leaving
// the input with a value that names no video shows the server's error toast. Play replaces the cover with the player
// cloned from the widget's <template> (the embed base and the id); Remove empties the input. The server stays the
// judge of what is saved.

const ID = /^[A-Za-z0-9_-]{11}$/;
const YOUTUBE_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'];
const HOSTS = [...YOUTUBE_HOSTS, 'youtu.be', 'vimeo.com', 'www.vimeo.com', 'player.vimeo.com'];

// As PHP's urldecode(): "+" is a space, a broken escape stays as written.
const decode = (text) => {
    try {
        return decodeURIComponent(text.replace(/\+/g, ' '));
    } catch {
        return text;
    }
};

// The id of the video $value names, or null: the rules of YouTubeType::normalize() and VideoUrl::parse(). The link is
// split as parse_url() splits it, not by the URL parser, which would repair what the server refuses (a protocol-relative
// "//host", "https:host", backslashes, dot segments).
function videoId(value) {
    const text = value.trim();
    if (ID.test(text)) {
        return text;
    }
    // Without a scheme the text is a host and path, and the server assumes https.
    const url = /^[A-Za-z][A-Za-z0-9+.-]*:/.test(text) ? text : '//' + text;
    const parts = /^(?:([A-Za-z][A-Za-z0-9+.-]*):)?\/\/([^\/?#]*)([^?#]*)(?:\?([^#]*))?/.exec(url);
    if (parts === null) {
        return null;
    }
    const [, scheme = 'https', authority, path, query = ''] = parts;
    if (!['http', 'https'].includes(scheme.toLowerCase())) {
        return null;
    }
    // The host without the user part and the port.
    let host = authority.slice(authority.lastIndexOf('@') + 1).replace(/:[0-9]*$/, '').toLowerCase();
    if (!HOSTS.includes(host) && host.startsWith('www.')) {
        host = host.slice(4);
    }
    if (host === 'youtu.be') {
        const id = path.replace(/^\/+|\/+$/g, '');

        return ID.test(id) ? id : null;
    }
    if (!YOUTUBE_HOSTS.includes(host)) {
        return null;
    }
    if (path === '/watch') {
        // As parse_str(): the last v wins, and v[] makes it an array, which names no video.
        let id = null;
        for (const pair of query.split('&')) {
            const [name, ...rest] = pair.split('=');
            const key = decode(name);
            if (key === 'v' || key.startsWith('v[')) {
                id = key === 'v' ? decode(rest.join('=')) : null;
            }
        }

        return id !== null && ID.test(id) ? id : null;
    }
    const match = /^\/(?:embed|shorts|live)\/([A-Za-z0-9_-]{11})$/.exec(path);

    return match === null ? null : match[1];
}

for (const widget of document.querySelectorAll('[data-youtube]')) {
    const input = document.getElementById(widget.dataset.youtubeInput ?? '');
    const preview = widget.querySelector('[data-youtube-preview]');
    const image = widget.querySelector('[data-youtube-image]');
    const play = widget.querySelector('[data-youtube-play]');
    const open = widget.querySelector('[data-youtube-open]');
    const remove = widget.querySelector('[data-youtube-remove]');
    const player = widget.querySelector('template[data-youtube-player]');
    if (!(input instanceof HTMLInputElement) || [preview, image, play, open, remove, player].includes(null)) {
        continue;
    }
    // The error toast this widget shows (error toasts stay until closed): a new one replaces it.
    let toast = null;
    let timer = 0;
    // The video the card shows (the server's, then the last one read).
    let shown = widget.dataset.youtubeId ?? '';

    // The card of the video the input names, the cover in place of a running player; hidden without a video. The
    // cover is fetched again only for another video.
    const update = () => {
        const id = videoId(input.value);
        preview.replaceChildren(image, play);
        widget.hidden = id === null;
        if (id === null || shown === id) {
            return;
        }
        image.src = widget.dataset.youtubeThumb.replace('{id}', id);
        open.href = widget.dataset.youtubeWatch + id;
        shown = id;
    };

    input.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(update, 300);
    });
    // Leaving the input with a value that names no video reports it once.
    input.addEventListener('change', () => {
        clearTimeout(timer);
        update();
        const template = widget.querySelector('template[data-youtube-invalid]')?.content.firstElementChild ?? null;
        if (input.value.trim() === '' || !widget.hidden || template === null) {
            return;
        }
        toast?.remove();
        toast = template.cloneNode(true);
        document.querySelector('[data-toasts]')?.append(toast);
    });
    // Play loads the player only now, started at once, in place of the cover.
    play.addEventListener('click', () => {
        const id = shown;
        const frame = player.content.firstElementChild.cloneNode(true);
        frame.src = widget.dataset.youtubeEmbed + id + '?autoplay=1';
        preview.replaceChildren(frame);
        frame.focus();
    });
    // Remove empties the input as typing would: autosave and the "unsaved changes" check hear it.
    remove.addEventListener('click', () => {
        input.value = '';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        input.focus();
    });
    update();
}
