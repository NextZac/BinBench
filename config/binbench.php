<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    |
    | Whether new accounts can be created from the login page. With an invite
    | code set, registering requires it, so only people you give it to can
    | sign up. Every user only sees their own files and definitions.
    |
    */

    'registration' => (bool) env('BINBENCH_REGISTRATION', true),

    'invite_code' => env('BINBENCH_INVITE_CODE'),

];
