<?php

namespace App\Rules;

use App\Services\Zgw\ZgwReader;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The value must be a URL on one of the configured ZGW hosts, the same origins
 * the ZGW client is allowed to send requests to.
 */
class ConfiguredZgwHost implements ValidationRule
{
    public function __construct(private readonly ZgwReader $reader) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->reader->isAllowedUrl($value)) {
            $fail('The :attribute must point to a configured ZGW host.');
        }
    }
}
