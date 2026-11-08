<?php

namespace App\Support;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * No email ever goes to a demo account. The demo logins use fictional
 * organisations' .com domains that someone else could own
 * (config demo.mail_blocked_domains), so the mail layer refuses any message
 * with one of them among its recipients, in every environment, production
 * included. There is no switch to turn this off.
 *
 * Exact domains only: a lookalike, a subdomain, uselocalgoods.com itself or a
 * reviewer's real domain is never blocked. Reviewer magic links aren't
 * emailed at all (ReviewerLinks), so this never touches them.
 */
class DemoMailGuard
{
    public static function isBlocked(?string $address): bool
    {
        $address = mb_strtolower(trim((string) $address));
        $at = strrpos($address, '@');

        return $at !== false && in_array(substr($address, $at + 1), self::domains(), true);
    }

    /** True if any recipient (To, Cc or Bcc) is a demo account: the whole message is refused. */
    public static function refuses(Email $message): bool
    {
        $recipients = [...$message->getTo(), ...$message->getCc(), ...$message->getBcc()];

        return collect($recipients)->contains(fn (Address $to) => self::isBlocked($to->getAddress()));
    }

    /** Listener for every mailer: returning false cancels the send. */
    public static function onMessageSending(MessageSending $event): ?bool
    {
        if (! self::refuses($event->message)) {
            return null;
        }

        // Never the address itself.
        Log::info('Mail to a demo account refused.', ['recipients' => count($event->message->getTo()) + count($event->message->getCc()) + count($event->message->getBcc())]);

        return false;
    }

    /** Listener for notifications: stops a mail notification before it is built. */
    public static function onNotificationSending(NotificationSending $event): ?bool
    {
        if ($event->channel !== 'mail' || ! method_exists($event->notifiable, 'routeNotificationFor')) {
            return null;
        }

        $route = $event->notifiable->routeNotificationFor('mail', $event->notification);
        $addresses = is_array($route) ? [...array_keys($route), ...array_values($route)] : [$route];

        return collect($addresses)->contains(fn ($a) => is_string($a) && self::isBlocked($a)) ? false : null;
    }

    /** @return list<string> */
    public static function domains(): array
    {
        return array_values(array_map(fn ($d) => mb_strtolower(trim($d)), (array) config('demo.mail_blocked_domains', [])));
    }
}
