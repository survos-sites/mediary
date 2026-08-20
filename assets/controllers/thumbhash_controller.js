import { Controller } from '@hotwired/stimulus';
import { thumbHashToDataURL } from 'thumbhash';

/*
 * Paints a ThumbHash blur placeholder behind a lazily-loaded image, so a card has
 * something image-shaped the instant the HTML lands instead of an empty grey box.
 *
 * The hash rides in the page already — imgproxy Pro's /info returns `thumb_hash` on
 * every asset (see AssetWorkflow::onInfo) — so this costs one ~25-byte string and no
 * extra request. The real imgproxy thumbnail then fades in over the blur.
 *
 * Two encodings exist for the same ThumbHash bytes, hence the `encoding` value:
 *   hex     — imgproxy's /info `thumb_hash`            (the default; what we use now)
 *   base64  — Survos\ThumbHashBundle's convertHashToString(), unpadded
 * See docs/local-image-analysis.md.
 *
 *   <a class="ratio ratio-4x3"
 *      data-controller="thumbhash"
 *      data-thumbhash-hash-value="28080E0480E6..."
 *      data-thumbhash-encoding-value="hex">
 *       <img data-thumbhash-target="image" src="..." loading="lazy">
 *   </a>
 */
export default class extends Controller {
    static targets = ['image'];

    static values = {
        hash: String,
        encoding: { type: String, default: 'hex' },
    };

    connect() {
        const bytes = this.decodeHash();
        if (!bytes) {
            return;
        }

        let dataUrl;
        try {
            dataUrl = thumbHashToDataURL(bytes);
        } catch {
            // A malformed hash must never cost us the thumbnail underneath it.
            return;
        }

        // `cover` rather than `contain`: the real <img> is object-fit-contain, so a
        // portrait in a 4x3 slot leaves bars. Filling them with the blurred backdrop
        // reads better than grey, and stays put after the image fades in.
        this.element.style.backgroundImage = `url("${dataUrl}")`;
        this.element.style.backgroundSize = 'cover';
        this.element.style.backgroundPosition = 'center';
    }

    imageTargetConnected(image) {
        if (!this.hasHashValue) {
            return;
        }

        image.style.transition = 'opacity 400ms ease';

        // Already in cache (bfcache, re-render, instant 304): don't fade a visible image out.
        if (image.complete && image.naturalWidth > 0) {
            image.style.opacity = '1';
            return;
        }

        image.style.opacity = '0';
        const reveal = () => { image.style.opacity = '1'; };
        // On error too — a broken image should fall back to the browser's own
        // indicator rather than sitting invisible on top of the blur forever.
        image.addEventListener('load', reveal, { once: true });
        image.addEventListener('error', reveal, { once: true });
    }

    /** @return {Uint8Array|null} */
    decodeHash() {
        const raw = this.hasHashValue ? this.hashValue.trim() : '';
        if (!raw) {
            return null;
        }

        try {
            if (this.encodingValue === 'base64') {
                // convertHashToString() rtrims '='; restore the padding before decoding.
                const padded = raw.replace(/-/g, '+').replace(/_/g, '/')
                    + '==='.slice((raw.length + 3) % 4);
                return Uint8Array.from(atob(padded), (c) => c.charCodeAt(0));
            }

            const pairs = raw.match(/../g);
            return pairs ? Uint8Array.from(pairs, (h) => parseInt(h, 16)) : null;
        } catch {
            return null;
        }
    }
}
