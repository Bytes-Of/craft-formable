<?php

declare(strict_types=1);

namespace bytesof\formable\base;

/**
 * An email-marketing integration: subscribes the submitter to a list/audience,
 * mapping form fields onto the list's merge tags.
 *
 * The category base exists to group these in the CP and to give the shared
 * "one of the mappable targets is the email address" contract a home - the
 * concrete provider (Mailchimp) supplies the actual API calls.
 *
 * @api
 */
abstract class EmailMarketing extends Integration
{
    public static function category(): string
    {
        return self::CATEGORY_EMAIL;
    }

    /**
     * The mappable target that carries the subscriber's email address - the one
     * field every provider in this category requires. Its resolved value is
     * pulled out of the mapping before the rest are treated as merge data.
     */
    protected function emailField(): string
    {
        return 'EMAIL';
    }

    /**
     * The email address from a resolved mapping, or null when the mapped field
     * produced nothing usable. A subscribe with no address can't succeed, so
     * the provider reports a permanent failure rather than calling the API.
     *
     * @param array<string, mixed> $mapped
     */
    protected function mappedEmail(array $mapped): ?string
    {
        $email = $mapped[$this->emailField()] ?? null;
        $email = is_scalar($email) ? trim((string)$email) : '';

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }
}
