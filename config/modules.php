<?php

return [
    /*
     * Each entry is a top-level group; its subdirectories are the individual modules.
     * `path` is relative to the application base path.
     *
     * ['name' => 'catalog', 'namespace' => 'App\\Catalog', 'path' => 'app/Catalog'],
     */
    'groups' => [],

    /*
     * Middleware applied to a module route file, by filename: a middleware group
     * name or a list of middleware. Files not listed here load without middleware.
     */
    'route_middleware' => [
        'web.php' => 'web',
        'api.php' => 'api',
    ],

    /*
     * URI prefix applied to a module route file, by filename, as Laravel prefixes
     * routes/api.php with `api`. Files not listed here load without a prefix.
     */
    'route_prefixes' => [
        'api.php' => 'api',
    ],
];
