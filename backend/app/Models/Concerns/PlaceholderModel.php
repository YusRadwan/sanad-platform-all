<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ MARKER FOR A MODEL THAT IS NOT A REAL MODEL YET.                        │
 * │                                                                          │
 * │ Stage 3 builds the authorization ENGINE before most of the domain        │
 * │ exists. The eight skeleton policies in App\Policies must type-hint their │
 * │ resource parameter (`Section $section`, `Exam $exam`, …) or they are not │
 * │ signatures at all — and PHP will not even PARSE a type-hint whose class  │
 * │ is missing at the moment the method is called. So the classes have to    │
 * │ exist now.                                                               │
 * │                                                                          │
 * │ Every class carrying this interface is a TEMPORARY STAND-IN: table name, │
 * │ key configuration, casts, and nothing else. NO relationships, NO scopes, │
 * │ NO business rules, NO $fillable — because a half-built model that looks  │
 * │ finished is worse than an obviously unfinished one. The stage that owns  │
 * │ each table replaces the body wholesale and DROPS THIS INTERFACE.         │
 * │                                                                          │
 * │ Removing the interface is the deliberate hand-off signal, and            │
 * │ tests/Unit/Authorization/PolicySkeletonTest.php pins the exact set of    │
 * │ classes that still carry it — so finishing a model without updating that │
 * │ list fails the suite, and nobody can quietly ship a stub as if it were   │
 * │ the real thing.                                                          │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
interface PlaceholderModel
{
}
