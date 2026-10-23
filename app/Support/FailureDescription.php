<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use Throwable;
use Woweb\Zgw\Exceptions\ApiRequestException;

/**
 * A short description of why a job failed, safe to store and show in the admin panel.
 *
 * For an HTTP error only the status is kept, because the message of those exceptions contains
 * (part of) the response body. For a database error only the SQLSTATE is kept, because its
 * message contains the connection details and the query. Other messages are shortened, and numbers that look like a BSN
 * or another personal number and e-mail addresses are masked, since an error from a remote
 * system may repeat what it was sent.
 */
class FailureDescription
{
    public const MAX_LENGTH = 255;

    public static function from(Throwable $e): string
    {
        $status = match (true) {
            $e instanceof RequestException => $e->response->status(),
            $e instanceof ApiRequestException => $e->getResponse()->status(),
            default => null,
        };

        $text = match (true) {
            $status !== null => "HTTP {$status}",
            $e instanceof QueryException => 'SQLSTATE '.($e->errorInfo[0] ?? $e->getCode()),
            default => self::mask(Str::squish($e->getMessage())),
        };

        return Str::limit(class_basename($e).': '.$text, self::MAX_LENGTH - 3);
    }

    private static function mask(string $message): string
    {
        $message = (string) preg_replace('/[^\s@]+@[^\s@]+\.[^\s@]+/', '[e-mail]', $message);

        return (string) preg_replace('/(?<![\w-])\d{8,}(?![\w-])/', '[nummer]', $message);
    }
}
