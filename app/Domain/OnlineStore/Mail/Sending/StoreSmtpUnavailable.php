<?php

namespace App\Domain\OnlineStore\Mail\Sending;

use RuntimeException;

/**
 * A store's own SMTP server cannot be used (OS-10): refused by the SSRF guard or not set up. The message is plain
 * words without secrets, safe to show on the Emails tab.
 */
final class StoreSmtpUnavailable extends RuntimeException {}
