<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Marker interface: "this exception's own message is safe to send to the
 * client, verbatim".
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ WHY IT EXISTS                                                            │
 * │                                                                          │
 * │ App\Exceptions\Handler replaces the message of every 401/403/404 with a  │
 * │ generic phrase, because framework exceptions leak internals in theirs    │
 * │ (a ModelNotFoundException says "No query results for model               │
 * │ [App\Models\User] abc-123"). That was exactly right in Stage 0, when no  │
 * │ endpoint had anything to say at 401.                                     │
 * │                                                                          │
 * │ Stage 1 does. backend/'s AuthService answered 401 with SPECIFIC user-    │
 * │ facing Arabic — 'اسم المستخدم أو كلمة المرور غير صحيحة' vs               │
 * │ 'هذا الحساب غير مفعل' — and the login page renders both. Blanket         │
 * │ genericisation would replace them with "Unauthenticated" and break the   │
 * │ contract.                                                                │
 * │                                                                          │
 * │ An OPT-IN marker keeps both properties: a throw site that deliberately   │
 * │ authored a public message says so by type, and anything that did not     │
 * │ (every framework exception, every future accidental throw) still gets    │
 * │ the safe generic phrase. The default stays closed.                       │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
interface PublicMessage
{
}
