<?php

declare(strict_types=1);

namespace Spycx\FormsKit;

/** Resultado de FormGuard::check(). */
enum Verdict
{
    /** Pasa los filtros anti-spam. */
    case Ok;

    /** Bot (honeypot lleno o envío demasiado rápido): responder con un éxito falso. */
    case Spam;

    /** Token ausente, falsificado o caducado: pedir que recargue la página. */
    case Expired;
}
