<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The ONE way bytes leave storage/uploads.
 *
 * Port of backend/FileController::stream() + asciiFilename().
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE Content-Type IS THE **SNIFFED** ONE, PASSED IN EXPLICITLY, AND THE  ║
 * ║ FRAMEWORK'S OWN GUESS IS OVERRIDDEN.                                    ║
 * ║                                                                          ║
 * ║ BinaryFileResponse::prepare() guesses a Content-Type from the file when  ║
 * ║ none is set, and its guess is EXTENSION-BASED via Symfony's MimeTypes    ║
 * ║ map. That is the wrong source: the extension is the part of the stored   ║
 * ║ name we already trust least in a threat model built around content       ║
 * ║ lying about itself. StoredUploadPath::sniff() reads the real bytes and   ║
 * ║ whitelists the answer to eight inert types, so passing it here means an  ║
 * ║ SVG that somehow reached the disk is announced as octet-stream rather    ║
 * ║ than as image/svg+xml — i.e. it is downloaded, not executed.             ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── DISPOSITION: PRIVATE IS **ALWAYS** attachment. ────────────────────────┐
 * │ Even a PDF can carry active content in some viewers, and some browsers   │
 * │ render PDFs in-page in the site's own origin. Forcing a download means   │
 * │ the browser never executes anything here, whatever the body turns out    │
 * │ to be.                                                                   │
 * │                                                                          │
 * │ PUBLIC imagery uses `inline`, because an <img src> that downloads        │
 * │ instead of rendering is a broken storefront. That is safe SPECIFICALLY   │
 * │ because sniff() can only ever answer an inert raster type or             │
 * │ octet-stream for those categories — SVG being rejected outright at       │
 * │ upload time is the premise the whole `inline` decision rests on. If SVG  │
 * │ were ever added to an image kind's allow-list, THIS LINE becomes a       │
 * │ stored-XSS delivery mechanism.                                           │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── CACHE-CONTROL SPLITS THE SAME WAY, AND FOR A DIFFERENT REASON. ────────┐
 * │ public, max-age=86400 — a course banner is a static asset served to      │
 * │ everyone; caching it is the point.                                       │
 * │ private, no-store    — a note attachment is entitlement-gated. A shared  │
 * │ proxy caching it would serve it to the next requester WITHOUT the gate,  │
 * │ turning a subscription check into a one-time formality.                  │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * This response is a BinaryFileResponse and therefore passes through
 * App\Http\Middleware\ForceJsonEnvelope UNTOUCHED — wrapping a binary body in
 * {success,message,data} would corrupt every byte of it. Error paths (403/404
 * from the policy or the resolver) are ordinary exceptions and DO still get
 * the envelope, so the failure contract is unchanged.
 */
final class FileStream
{
    /**
     * @param  string  $absolutePath  already resolved AND contained by StoredUploadPath
     * @param  string  $mime          from StoredUploadPath::sniff(), never a stored value
     * @param  string  $downloadName  the human-readable name; hostile input
     * @param  bool    $public        inline+cacheable when true, attachment+no-store when false
     */
    public static function respond(
        string $absolutePath,
        string $mime,
        string $downloadName,
        bool $public,
    ): BinaryFileResponse {
        $disposition = $public ? 'inline' : 'attachment';

        $response = response()->file($absolutePath, [
            'Content-Type' => $mime,
            'Content-Disposition' => $disposition . '; filename="' . self::asciiFilename($downloadName) . '"',
            // Without nosniff a browser may re-interpret an
            // application/octet-stream body as HTML and run script in it.
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Frame-Options' => 'DENY',
        ]);

        /*
         * ╔══════════════════════════════════════════════════════════════════╗
         * ║ Cache-Control IS SET **AFTER** CONSTRUCTION, AND THAT IS NOT A   ║
         * ║ STYLE CHOICE — PASSING IT IN THE HEADER ARRAY DOES NOT WORK.     ║
         * ║                                                                  ║
         * ║ Symfony's BinaryFileResponse constructor takes `bool $public =    ║
         * ║ true` and, AFTER applying the supplied headers, calls            ║
         * ║ setPublic() — which adds the `public` directive and REMOVES      ║
         * ║ `private`. Laravel's response()->file() does not expose that     ║
         * ║ argument, so a 'private, no-store' passed in the array came back ║
         * ║ as **'no-store, public'**: a private, entitlement-gated document ║
         * ║ marked cacheable by shared proxies. Measured, not assumed.       ║
         * ║                                                                  ║
         * ║ Setting the header afterwards is the last write and wins.        ║
         * ╚══════════════════════════════════════════════════════════════════╝
         */
        $response->headers->set(
            'Cache-Control',
            $public ? 'public, max-age=86400' : 'private, no-store'
        );

        return $response;
    }

    /**
     * Reduce an arbitrary, user-supplied filename to something that cannot
     * escape a quoted header parameter.
     *
     * ┌── THIS IS HEADER-INJECTION DEFENCE, NOT COSMETICS. ─────────────────┐
     * │ The download name is the ORIGINAL filename the uploader chose. It   │
     * │ is hostile input even though the file it names is not. A value      │
     * │ containing `"` closes the quoted parameter; one containing CR/LF    │
     * │ splits the response into forged headers or a forged body.           │
     * │                                                                      │
     * │ The defence is an ALLOW-LIST, `[A-Za-z0-9._-]`, so it does not have │
     * │ to enumerate the dangerous characters — quotes, backslashes, CR,    │
     * │ LF, NUL, semicolons and every non-ASCII byte all fall outside it    │
     * │ and become '_'. An ARABIC filename therefore becomes underscores,   │
     * │ which is the intended, tested behaviour: RFC 5987 `filename*=`      │
     * │ would preserve it, and is deliberately NOT used because backend/    │
     * │ does not send it and adding it would change the header a live       │
     * │ frontend already parses.                                            │
     * │                                                                      │
     * │ basename() runs FIRST (after normalising '\' to '/') so a name like │
     * │ '../../etc/passwd' collapses to 'passwd' rather than to             │
     * │ '.._.._etc_passwd'. It changes nothing about safety — the name      │
     * │ never touches the filesystem — but it keeps the header readable.    │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public static function asciiFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? '';
        $name = substr($name, 0, 100);

        return $name === '' ? 'download' : $name;
    }

    /** Static-only. */
    private function __construct()
    {
    }
}
