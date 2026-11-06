<?php

return [
    'locales' => ['en' => ['label' => 'English', 'dir' => 'ltr']],
    'layout' => 'site-builder::layouts.site',
    'routes' => ['enabled' => true, 'prefix' => '', 'middleware' => ['web'], 'public' => true, 'preview' => true, 'preview_prefix' => '_site-builder/preview'],
    'assets' => ['public_css' => true],
    'auth' => ['legacy_actor_type' => null],
    'saved_sections' => ['exchange' => false, 'preserve_actor_ownership' => false],
    'documents' => ['max_depth' => 32, 'max_nodes' => 1000, 'max_bytes' => 2 * 1024 * 1024],
    'site_parts' => ['exchange' => false, 'preview_layout' => null, 'defaults' => []],
    'composition' => ['max_depth' => 32, 'max_nodes' => 1000, 'max_bytes' => 2 * 1024 * 1024, 'max_hops' => 8, 'max_revisions' => 128, 'max_mounts' => 256, 'max_structural_depth' => 4],
    'clipboard' => ['ttl_seconds' => 1800, 'snapshots_per_actor' => 10, 'bytes_per_actor' => 8 * 1024 * 1024, 'max_envelope_bytes' => 1024 * 1024],
    'history' => ['transitions' => 20, 'max_bytes' => 8 * 1024 * 1024, 'ttl_seconds' => 7200],
    'locks' => ['ttl_seconds' => 300, 'heartbeat_seconds' => 30],
    'revisions' => ['keep' => 20],
    'preview' => ['epoch' => env('SITE_BUILDER_PREVIEW_EPOCH', '1'), 'expiry_minutes' => 1440, 'widths' => ['desktop' => null, 'tablet' => 820, 'mobile' => 390]],
    'tokens' => ['color' => ['primary' => '#315b57'], 'space' => ['section' => '6rem', 'gutter' => '1.5rem']],
];
