<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->preserveEmptyStringsOnClearableWrites();
        $this->preserveVerbatimTextOnSettingsWrite();
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ STAGE 10 — THE **SECOND** GLOBAL LARAVEL MIDDLEWARE THAT SILENTLY    ║
     * ║ REWRITES A PORTED CONTRACT, AND THE FIRST TIME IT HAS MATTERED.      ║
     * ║ FOUND BY A FAILING TEST, NOT BY READING THE STACK.                   ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * `Illuminate\Foundation\Http\Middleware\TrimStrings` sits in the GLOBAL
     * stack immediately BEFORE ConvertEmptyStringsToNull (verified by dumping
     * the resolved kernel, not by assuming the framework default):
     *
     *     … ValidatePostSize -> TrimStrings -> ConvertEmptyStringsToNull
     *
     * backend/ is a hand-rolled framework with no such middleware, so every
     * string arrived exactly as sent and each service trimmed what it wanted
     * to trim, where it wanted to.
     *
     * ┌── WHY THIS IS A REAL DIFFERENCE AND NOT A COSMETIC ONE. ─────────────┐
     * │ backend/SettingsService trims `platform`, `type`, `url` and the      │
     * │ contact values — and DELIBERATELY DOES NOT TRIM `privacy_policy` or  │
     * │ `terms_conditions`. Those two are long-form documents pasted by an   │
     * │ admin; their leading blank line is CONTENT. Under the global         │
     * │ middleware they were arriving pre-trimmed, so v2 was storing a       │
     * │ different string than backend/ would for the same request.           │
     * │                                                                      │
     * │ Measured: SettingsValueSemanticsTest::test_policy_text_is_not_       │
     * │ trimmed failed with "\n  نص  \n" stored as "نص".                     │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * ┌── WHY skipWhen() FOR THE WHOLE ROUTE, NOT except() FOR TWO FIELDS. ──┐
     * │ `TrimStrings::except()` takes FIELD NAME PATTERNS and is GLOBAL — it │
     * │ would exempt anything called `settings.privacy_policy` on any future │
     * │ endpoint, and it leaves the route half-governed by two different     │
     * │ trimming authorities.                                                │
     * │                                                                      │
     * │ skipWhen() scoped to this one route restores backend/'s arrangement  │
     * │ EXACTLY: no global trimming at all, and App\Actions\Settings\        │
     * │ UpdateSettings does its own trim() on precisely the four fields      │
     * │ backend/ trims. ONE authority, in the same place backend/ put it.    │
     * │                                                                      │
     * │ It also makes UpdateSettingsRequest's trimmed-empty checks genuinely │
     * │ load-bearing: a `platform: '   '` now really does reach the          │
     * │ validator as three spaces (Laravel's `required` accepts it), and the │
     * │ explicit `trim(...) === ''` branch is what rejects it with backend/'s │
     * │ 'اسم المنصة مطلوب'. With TrimStrings active those branches were dead  │
     * │ code that happened to be masked into passing.                        │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * SCOPE IS DELIBERATELY NARROW — this ONE path, write verbs only. Every
     * other endpoint in Stages 0-9 was built and tested with TrimStrings
     * active; widening the exemption now would change their semantics with no
     * test asking for it. Recorded as open decision 10.8.
     */
    private function preserveVerbatimTextOnSettingsWrite(): void
    {
        TrimStrings::skipWhen(static function (Request $request): bool {
            return $request->isMethod('POST') && $request->is('api/v1/settings');
        });
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ STAGE 4 — A LARAVEL DEFAULT THAT SILENTLY BREAKS A PORTED CONTRACT.  ║
     * ║ MEASURED, NOT ANTICIPATED: the teacher field-semantics suite failed   ║
     * ║ in EIGHT places before this method existed.                          ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * Laravel ships `ConvertEmptyStringsToNull` in the GLOBAL middleware stack.
     * It rewrites every '' in every request to null before anything —
     * middleware, Form Request, controller or Action — can look at it.
     *
     * For most applications that is a convenience. For the teacher and student
     * profile writes it is fatal, because THE DIFFERENCE BETWEEN '' AND null IS
     * ITSELF THE CONTRACT:
     *
     *     avatarUrl: ''    "the admin pressed حذف وتصغير"  -> CLEAR the column
     *     avatarUrl: null  absent-equivalent (backend/'s isset()/?? idiom)
     *                                                      -> LEAVE IT ALONE
     *     (absent)                                          -> LEAVE IT ALONE
     *
     * backend/ was a hand-rolled framework with no such middleware, so ''
     * arrived as ''. Under Laravel's default, every "remove this photo" request
     * silently became a no-op that still answered 200 — which is EXACTLY the
     * historical production bug this stage exists to preserve the fix for,
     * re-introduced one layer further down, where no amount of care inside
     * App\Actions\Teacher\UpdateTeacher could have caught it.
     *
     * ┌── WHY skipWhen AND NOT REMOVING THE MIDDLEWARE ──────────────────────┐
     * │ Removing it globally would change the meaning of '' on EVERY         │
     * │ endpoint built in Stages 0-3 — most visibly registration, where a    │
     * │ `nullable|email` field currently accepts '' (because it arrives as   │
     * │ null) and would start rejecting it as a malformed address. That is a │
     * │ wide, untested blast radius to buy a narrow fix.                     │
     * │                                                                      │
     * │ skipWhen() is the framework's own first-party exemption hook         │
     * │ (Illuminate\Foundation\Http\Middleware\TransformsRequest). The       │
     * │ exemption is scoped to the routes whose contract actually needs it,  │
     * │ so everything else keeps Laravel's default behaviour unchanged.      │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * ┌── WHY PATH MATCHING AND NOT ROUTE NAMES ─────────────────────────────┐
     * │ This middleware is GLOBAL — it runs before the router has matched    │
     * │ anything, so `$request->route()` is still null and there is no name  │
     * │ to test. The path patterns below are the only identity available at  │
     * │ that point. They are anchored to the full `api/v1/admin/...` prefix  │
     * │ so they cannot widen by accident.                                    │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * The method guard matters too: only the WRITE verbs are exempted. A GET
     * carries no body for the middleware to rewrite, and leaving reads on the
     * default path keeps the exemption exactly as narrow as the contract.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ STAGE 5 / SESSION A EXTENDED THE LIST. THE MECHANISM IS UNCHANGED.   ║
     * ║                                                                      ║
     * ║ Open decision 4.1 made this an OBLIGATION on every later stage:      ║
     * ║ "كل مرحلة تضيف مسار كتابة يفرّق بين '' و null **يجب** أن تضيف نمط     ║
     * ║  مساره إلى هذه الدالة" — and warned that forgetting fails SILENTLY   ║
     * ║ with a 200. The course and section writes are exactly such routes:    ║
     * ║                                                                      ║
     * ║   thumbnail_url: ''   the instructor pressed "remove image" → CLEAR   ║
     * ║   description:   ''   likewise → CLEAR                                ║
     * ║   title:         ''   must 422 (min:3) — impossible to distinguish    ║
     * ║                       from an absent title once '' has become null    ║
     * ║   discount_price:''   must be IGNORED, which                          ║
     * ║                       CourseWriteRequest::prepareForValidation()      ║
     * ║                       implements by REMOVING the key — it cannot tell ║
     * ║                       '' from a deliberate null without this          ║
     * ║                       exemption either                                ║
     * ║                                                                      ║
     * ║ Note the last one especially: the exemption is what makes the         ║
     * ║ INVERTED money-column convention expressible at all. Without it, ''   ║
     * ║ and null are the same value by the time any application code runs,    ║
     * ║ and the two halves of the field-semantics table collapse into one.    ║
     * ║                                                                      ║
     * ║ The patterns are anchored to full `api/v1/...` paths so they cannot   ║
     * ║ widen by accident, and `sections/*` covers PUT /sections/{id} without ║
     * ║ touching the public GET (the method guard above already excludes it). ║
     * ║ `/groups*` is included for the same '' -> title reason.               ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * RENAMED from preserveEmptyStringsOnProfileWrites(): it is no longer only
     * about profiles, and a name that lies about its scope is how the next
     * stage fails to notice it should have edited this list.
     */
    private function preserveEmptyStringsOnClearableWrites(): void
    {
        ConvertEmptyStringsToNull::skipWhen(static function (Request $request): bool {
            if (!\in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
                return false;
            }

            return $request->is('api/v1/admin/teachers')
                || $request->is('api/v1/admin/teachers/*')
                || $request->is('api/v1/admin/students/*')
                // ── Stage 5 / Session A ──────────────────────────────────
                || $request->is('api/v1/courses')
                || $request->is('api/v1/courses/*')
                || $request->is('api/v1/sections/*')
                || $request->is('api/v1/groups')
                || $request->is('api/v1/groups/*')
                /*
                 * ── Stage 5 / Session B ──────────────────────────────────
                 *
                 * `PUT /api/v1/content-items/{id}` is the third route family
                 * to need this, for THREE separate '' semantics that Laravel's
                 * default would collapse into one:
                 *
                 *   description:   ''  -> CLEAR    (TEXT column; backend/'s
                 *                                   array_filter wrote it)
                 *   thumbnail_url: ''  -> CLEAR    (UrlValidator::nullable()
                 *                                   maps '' to null on purpose)
                 *   content_text:  ''  -> CREATE A `notes` ROW with an empty
                 *                         body. This one is the subtle one:
                 *                         PersistNoteDetail's guard is
                 *                         isset(), so '' creates the row and
                 *                         null creates NOTHING AT ALL. Under
                 *                         the default middleware the two are
                 *                         the same value and the row silently
                 *                         stops being created.
                 *
                 * The three POST routes need no new pattern: they live under
                 * `api/v1/sections/*` and `api/v1/groups/*`, already listed.
                 *
                 * Open decision 4.1 made adding this entry an OBLIGATION, and
                 * warned that forgetting it fails SILENTLY with a 200. Pinned
                 * by ContentItemFieldSemanticsTest.
                 */
                || $request->is('api/v1/content-items/*')
                /*
                 * ── Stage 6 / Session A ──────────────────────────────────
                 *
                 * The question bank is the FOURTH route family to need this,
                 * and the one with the most `''` meanings per endpoint —
                 * THREE different ones across two routes, all of which the
                 * default middleware would collapse into a single null:
                 *
                 *   PATCH /question-groups/{id}
                 *     title: ''  -> KEEP the old title. The legacy guard is
                 *                   `!empty()`, so '' is falsy and the
                 *                   existing value is re-written.
                 *     grade: ''  -> WRITE ''. The guard is
                 *                   `array_key_exists()`, so '' lands in the
                 *                   column while an explicit null CLEARS it.
                 *                   Two idioms, adjacent lines, one request —
                 *                   and the exemption is what makes the pair
                 *                   distinguishable at all.
                 *
                 *   PATCH /questions/{id}
                 *     body: ''            -> WRITE '' (TEXT; guard is
                 *                            `isset()`, and isset('') is
                 *                            TRUE while isset(null) is FALSE,
                 *                            so the middleware would turn a
                 *                            real write into a no-op).
                 *     correct_answer: ''  -> WRITE '' (guard is
                 *                            array_key_exists; null CLEARS).
                 *
                 * POST /question-groups needs it too: without the exemption
                 * `title: ''` arrives as null and the 422 says "the field must
                 * be a string" instead of the contract's 'العنوان مطلوب'.
                 *
                 * Open decision 4.1 made adding these an OBLIGATION and warned
                 * that forgetting fails SILENTLY with a 200. Pinned by
                 * QuestionBankFieldSemanticsTest.
                 */
                || $request->is('api/v1/question-groups')
                || $request->is('api/v1/question-groups/*')
                || $request->is('api/v1/questions/*')
                /*
                 * ── Stage 8 — NOTIFICATIONS ──────────────────────────────
                 *
                 * ┌── THIS ENTRY IS **BELT AND BRACES**, AND SAYING SO IS ──┐
                 * │ MORE USEFUL THAN PRETENDING OTHERWISE.                  │
                 * │                                                         │
                 * │ Stage 8 is the first stage since 4.1 whose contract does │
                 * │ NOT strictly require the exemption, and that was checked │
                 * │ field by field rather than assumed:                      │
                 * │                                                         │
                 * │   linkUrl / link  ''  -> clear to null                   │
                 * │                   null -> clear to null   ← SAME ANSWER  │
                 * │   title / content ''  -> 422 (`required`, implicit)      │
                 * │                   null -> 422 (`required`)  ← SAME       │
                 * │                                                         │
                 * │ Every field agrees on '' and null; only PRESENCE is      │
                 * │ load-bearing, and ConvertEmptyStringsToNull preserves    │
                 * │ presence — it rewrites the value, never the key. So the  │
                 * │ PATCH contract ("absent = untouched") holds either way.  │
                 * │                                                         │
                 * │ THE ENTRY IS ADDED ANYWAY, FOR TWO REASONS:              │
                 * │  1. the tested semantics then hold AT THE LAYER THAT     │
                 * │     WRITES THEM, rather than depending on a global       │
                 * │     middleware happening to agree — the field-semantics  │
                 * │     suite asserts what the Form Request sees, not what   │
                 * │     survived a coincidence upstream;                     │
                 * │  2. the moment a later stage adds ONE clearable field    │
                 * │     whose '' and null differ (a `body: ''` that should   │
                 * │     write an empty string, say), the exemption is        │
                 * │     already in place — and 4.1's warning is that         │
                 * │     forgetting it fails SILENTLY with a 200.             │
                 * │                                                         │
                 * │ Pinned by NotificationFieldSemanticsTest, which asserts  │
                 * │ all three states (absent / '' / real) of every field on  │
                 * │ both write surfaces.                                     │
                 * └─────────────────────────────────────────────────────────┘
                 */
                || $request->is('api/v1/announcements')
                || $request->is('api/v1/announcements/*')
                || $request->is('api/v1/admin/broadcasts')
                || $request->is('api/v1/admin/broadcasts/*')
                /*
                 * ── Stage 10 — PLATFORM SETTINGS ─────────────────────────
                 *
                 * ╔══════════════════════════════════════════════════════════╗
                 * ║ THE FOURTH AFFECTED MODULE, AND THE ONLY ONE WHOSE       ║
                 * ║ FAILURE MODE IS A **422 ON AN ORDINARY SAVE** RATHER     ║
                 * ║ THAN A SILENT NO-OP.                                     ║
                 * ║                                                          ║
                 * ║ Everywhere above, leaving the default middleware active   ║
                 * ║ meant a "clear this field" request quietly did nothing    ║
                 * ║ and still answered 200. Here it means the admin cannot    ║
                 * ║ save the settings page AT ALL, on a fresh installation,   ║
                 * ║ from the very first click.                               ║
                 * ║                                                          ║
                 * ║ WHY — and this is measurable, not theoretical.            ║
                 * ║ PlatformSettings.tsx's handleSave() always sends a        ║
                 * ║ COMPLETE snapshot: all ELEVEN rows of its hard-coded      ║
                 * ║ social-platform catalogue and all FIVE support contacts,  ║
                 * ║ whether configured or not. On a new install that is       ║
                 * ║ sixteen entries whose `url` / `value` is the EMPTY        ║
                 * ║ STRING, plus `privacy_policy: ''` and                     ║
                 * ║ `terms_conditions: ''`.                                  ║
                 * ║                                                          ║
                 * ║ ConvertEmptyStringsToNull rewrites every one of them to   ║
                 * ║ null. `socialLinks.*.url` then fails its `string` rule    ║
                 * ║ (null is not a string) — eleven times — and the save      ║
                 * ║ answers 422 'الرابط يجب أن يكون نصاً' for a payload the   ║
                 * ║ frontend considers completely normal.                    ║
                 * ║                                                          ║
                 * ║ AND '' IS ALSO SEMANTICALLY REAL HERE, not just a type    ║
                 * ║ accident: backend/ treats `url: ''` as "this platform is  ║
                 * ║ in the catalogue but is not configured yet" and STORES    ║
                 * ║ the empty string, which is what lets the admin form       ║
                 * ║ re-render the row as an empty input instead of losing it. ║
                 * ╚══════════════════════════════════════════════════════════╝
                 *
                 * ┌── WHY THE SINGLE-FIELD TESTS ARE NOT ENOUGH. ───────────┐
                 * │ A test that posts `{socialLinks: [{platform: 'X', url:  │
                 * │ ''}]}` and asserts 200 passes even with this line        │
                 * │ DELETED, as long as some other field in the fixture      │
                 * │ happens to be non-empty — the middleware is global and   │
                 * │ order-independent, so a narrow fixture can dodge it by   │
                 * │ accident. The test that actually pins this entry is      │
                 * │ SettingsRealPayloadTest::test_the_real_frontend_payload_ │
                 * │ saves_with_all_its_empty_strings, which submits the      │
                 * │ complete ~11-row snapshot and asserts 200 — the shape    │
                 * │ that is guaranteed to trip the middleware if it runs.    │
                 * └─────────────────────────────────────────────────────────┘
                 *
                 * GET /api/v1/settings is NOT exempted and needs no entry: the
                 * method guard at the top of this closure already limits the
                 * exemption to POST/PUT/PATCH, and a GET has no body to rewrite.
                 */
                || $request->is('api/v1/settings');
        });
    }
}
