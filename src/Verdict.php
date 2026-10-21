<?php

declare(strict_types=1);

namespace Spycx\FormsKit;

/** Result of FormGuard::check(). */
enum Verdict
{
    /** Passes the anti-spam filters. */
    case Ok;

    /** Bot (honeypot filled or submitted too fast): answer with a fake success. */
    case Spam;

    /** Missing, forged or expired token: ask the user to reload the page. */
    case Expired;
}
