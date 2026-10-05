<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\SafeUrl;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The VALIDATOR-LAYER face of App\Support\SafeUrl.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ WHY THIS RUNS AT THE FORM REQUEST AND NOT AT THE PERSISTER.             ║
 * ║                                                                          ║
 * ║ backend/ContentItemService validates URLs TWICE and says why:            ║
 * ║   validateUrls()    before content_items::create()                        ║
 * ║   saveTypeDetail()  again, because it is the single write path to the     ║
 * ║                     videos/links tables and must be safe on its own       ║
 * ║                                                                          ║
 * ║ The first call exists because the second one runs AFTER the parent row   ║
 * ║ is inserted — so a rejected URL used to abort the request with an        ║
 * ║ ORPHANED `content_items` row already committed. In backend-v2 the        ║
 * ║ rejection is a Form Request rule, i.e. it happens before the controller  ║
 * ║ is entered at all, so no parent row can exist to be orphaned. That is    ║
 * ║ the same guarantee, obtained structurally instead of by remembering to   ║
 * ║ pre-validate.                                                            ║
 * ║                                                                          ║
 * ║ Pinned by ContentItemUrlSafetyTest::test_a_rejected_url_leaves_no_       ║
 * ║ content_items_row_behind, which asserts against the DATABASE, not the    ║
 * ║ response.                                                                ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── `$allowBlank` MIRRORS UrlValidator::nullable() vs ::required(). ───────┐
 * │ backend/ has two entry points and they differ on ONE case:              │
 * │                                                                          │
 * │   required($v)   ''  -> 422        video_url, link url                  │
 * │   nullable($v)   ''  -> null       thumbnail_url ("remove the poster")   │
 * │                                                                          │
 * │ So `thumbnail_url: ''` is a legitimate CLEAR instruction and must reach  │
 * │ the persister as such, while `video_url: ''` is a malformed request.     │
 * │ That is also why these routes are on AppServiceProvider's                │
 * │ preserveEmptyStringsOnClearableWrites() allow-list: without it Laravel   │
 * │ rewrites the '' to null in global middleware and the two cases become    │
 * │ indistinguishable before any rule runs.                                  │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * The message is backend/UrlValidator::required()'s literal string; the admin
 * forms render it verbatim.
 */
final class SafeUrlValue implements ValidationRule
{
    public const MESSAGE = 'الرابط غير صالح: يُسمح فقط بروابط تبدأ بـ http:// أو https:// أو بمسار داخلي يبدأ بـ /';

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THIS RULE MUST BE **IMPLICIT**, AND FINDING OUT WHY COST A FAILING   ║
     * ║ TEST.                                                                ║
     * ║                                                                      ║
     * ║ Laravel skips every NON-implicit rule when the value is a string that ║
     * ║ trims to '' (Validator::presentOrRuleIsImplicit). So without this     ║
     * ║ flag, `video_url: ''` sailed past the allow-list untouched and        ║
     * ║ PersistVideoDetail — whose guard is `!== null`, matching backend/'s   ║
     * ║ isset() — happily wrote a `videos` row with an EMPTY `video_url`.     ║
     * ║ backend/ answers 422 there (UrlValidator::required('') fails          ║
     * ║ isSafe()), and an empty <video src> is a broken lesson either way.    ║
     * ║                                                                      ║
     * ║ `public $implicit` is the framework's own hook:                       ║
     * ║ InvokableValidationRule::make() reads it and wraps the rule in a      ║
     * ║ subclass implementing ImplicitRule. It is NOT a `required` — the      ║
     * ║ field may still be absent, and `nullable` still short-circuits an     ║
     * ║ explicit null before any rule runs. Implicit only means "run me for   ║
     * ║ the blank string too", which is exactly the case in dispute.          ║
     * ║                                                                      ║
     * ║ $allowBlank then decides what to DO with that blank string, which is  ║
     * ║ the whole nullable-vs-required distinction below.                     ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public bool $implicit = true;

    /**
     * @param  bool  $allowBlank  TRUE for fields where '' means "clear it"
     *                            (UrlValidator::nullable's contract).
     */
    public function __construct(private readonly bool $allowBlank = false)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // An explicit null is "absent" on every URL field — backend/'s guards
        // are isset()/`?? null`, which cannot tell the two apart. `nullable`
        // normally handles this, but an IMPLICIT rule runs anyway, so the
        // branch has to exist here too.
        if ($value === null) {
            return;
        }

        if ($this->allowBlank && \is_string($value) && \trim($value) === '') {
            return;
        }

        if (!SafeUrl::isSafe($value)) {
            $fail(self::MESSAGE);
        }
    }
}
