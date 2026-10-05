<?php

return [
    App\Providers\AppServiceProvider::class,

    /*
     * STAGE 3. Registers Gate::before (the single admin bypass) and the
     * model => policy map. Laravel 12 would discover the policies by naming
     * convention on its own; the explicit registration is what makes a missing
     * or mis-named policy a FAILING TEST instead of a silently unprotected
     * model. See the class docblock.
     */
    App\Providers\AuthServiceProvider::class,
];
