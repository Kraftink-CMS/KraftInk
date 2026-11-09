<?php

namespace App\Kraft;

use HTMLPurifier_Config;
use HTMLPurifier;

class Html
{
    public static function clean(?string $html): string
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('Cache.SerializerPath', storage_path('framework/cache'));
        $config->set(
            'HTML.Allowed',
            'p,br,strong,b,em,i,u,s,ul,ol,li,blockquote,pre,code,h2,h3,h4,hr,a[href|title],img[src|alt|width|height],table,thead,tbody,tr,th,td',
        );
        $config->set('URI.AllowedSchemes', ['https' => true, 'http' => true, 'mailto' => true]);

        return new HTMLPurifier($config)->purify($html ?? '');
    }
}
