<?php

namespace App\Services\Content;

/** Public CMS output boundary; historical source content remains editable. */
final class SafeHtml
{
    private static ?\HTMLPurifier $purifier = null;

    public static function render(?string $html): string
    {
        if ($html === null || $html === '') return '';
        if (self::$purifier === null) {
            $config = \HTMLPurifier_Config::createDefault();
            $config->set('Core.Encoding', 'UTF-8');
            $config->set('Cache.DefinitionImpl', null);
            $config->set('HTML.Allowed', 'p[style],div[style],span[style],br,hr,h1,h2,h3,h4,h5,h6,strong,b,em,i,u,s,strike,blockquote,pre,code,ul,ol,li,a[href|title|target|rel],img[src|alt|title|width|height],table,caption,thead,tbody,tfoot,tr,th[colspan|rowspan|scope],td[colspan|rowspan]');
            $config->set('CSS.AllowedProperties', ['text-align','font-weight','font-style','text-decoration']);
            $config->set('URI.AllowedSchemes', ['https'=>true,'http'=>true,'mailto'=>true]);
            $config->set('Attr.EnableID', false);
            $config->set('Attr.AllowedFrameTargets', ['_blank']);
            $config->set('HTML.TargetNoopener', true);
            $config->set('HTML.TargetNoreferrer', true);
            self::$purifier = new \HTMLPurifier($config);
        }
        return self::$purifier->purify($html);
    }
}
