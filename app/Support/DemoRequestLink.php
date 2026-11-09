<?php

namespace App\Support;

/**
 * The one mailto link behind every "Request a demo" button. The visitor's own
 * email app sends the message; Local Goods sends nothing.
 */
class DemoRequestLink
{
    public const SUBJECT = 'Local Goods demo request';

    public const BODY_LINES = [
        'Organization:',
        'Your name:',
        'Role:',
        'Best time to talk:',
        '',
        'Anything else we should know:',
    ];

    public static function address(): string
    {
        return (string) config('localgoods.demo_email');
    }

    public static function url(): string
    {
        return 'mailto:'.self::address()
            .'?subject='.rawurlencode(self::SUBJECT)
            .'&body='.rawurlencode(implode("\r\n", self::BODY_LINES));
    }
}
