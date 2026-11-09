<?php

if (!class_exists(KraftManifest::class, false)) {
    require_once dirname(__DIR__).'/updater/manifest.php';
}

return KraftManifest::read(dirname(__DIR__), 'cms', 'kraftink');
