<?php

return [
    /*
    |--------------------------------------------------------------------------
    | RBAC enforcement master switch
    |--------------------------------------------------------------------------
    |
    | When true (default), the AccessScopeScope global scope filters
    | KnowledgeDocument reads by the authenticated user's project memberships
    | and explicit ACL denies, and the EnsureProjectAccess middleware rejects
    | unauthorised project keys.
    |
    | Set RBAC_ENFORCED=false in .env to bypass both during migration or an
    | emergency rollback — the scope and middleware become no-ops, and only
    | authentication from PR2 remains active. Production MUST stay on `true`.
    |
    */
    'enforced' => env('RBAC_ENFORCED', true),
];
