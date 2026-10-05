<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base Form Request that keeps Laravel's validator emitting the SAME Arabic
 * messages backend/app/Http/Requests/Validator.php produced.
 *
 * WHY THIS EXISTS
 * ---------------
 * The legacy validator hand-wrote its messages, e.g.
 *
 *     required  ->  "الحقل studentPhone مطلوب"
 *     digits:11 ->  "الحقل studentPhone يجب أن يتكون من 11 أرقام"
 *
 * Those strings reach the user: the signup wizard renders `data.errors[field]`
 * verbatim. Laravel's defaults are English (there is no ar lang file in this
 * project) and, worse, its `:attribute` placeholder humanises the field name —
 * `studentPhone` would render as "student phone", so even a translated message
 * would not match. Both halves are fixed here, once:
 *
 *  * messages()   — the legacy templates, per rule.
 *  * attributes() — an IDENTITY map built from the rule keys, so `:attribute`
 *                   interpolates the literal field name the client sent.
 *
 * Putting this on a shared base rather than in a lang file is deliberate: a
 * global ar/validation.php would silently restyle every future endpoint's
 * errors too, including ones whose wording is not a contract.
 *
 * Subclasses may of course override any individual key.
 */
abstract class ApiFormRequest extends FormRequest
{
    /**
     * Route access is decided by middleware (auth / role), not here. Returning
     * false would produce a 403 from the wrong layer and bypass the middleware
     * table that documents the real policy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => 'الحقل :attribute مطلوب',
            'string' => 'الحقل :attribute يجب أن يكون نصاً',
            'email' => 'الحقل :attribute يجب أن يكون بريداً إلكترونياً صحيحاً',
            'numeric' => 'الحقل :attribute يجب أن يكون رقماً',
            'min.string' => 'الحقل :attribute يجب ألا يقل عن :min أحرف',
            'max.string' => 'الحقل :attribute يجب ألا يزيد عن :max أحرف',
            'digits' => 'الحقل :attribute يجب أن يتكون من :digits أرقام',
        ];
    }

    /**
     * Identity map: `studentPhone` stays `studentPhone`, never "student phone".
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $names = [];

        foreach (array_keys($this->rules()) as $field) {
            $field = (string) $field;
            $names[$field] = $field;
        }

        return $names;
    }
}
