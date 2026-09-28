<?php

declare(strict_types=1);

namespace bytesof\formable\base;

/**
 * A CRM integration: creates or updates a contact from the submission, mapping
 * form fields onto the CRM's contact properties.
 *
 * Like {@see EmailMarketing}, the category base groups these in the CP and
 * declares the shared "the mapped values are contact properties" contract; the
 * concrete provider (HubSpot) supplies the API calls.
 *
 * @api
 */
abstract class Crm extends Integration
{
    public static function category(): string
    {
        return self::CATEGORY_CRM;
    }

    /**
     * Drops the targets an author left unmapped (or that resolved to nothing)
     * from a resolved mapping, so an empty answer never overwrites an existing
     * contact property with a blank.
     *
     * @param array<string, mixed> $mapped
     * @return array<string, string>
     */
    protected function nonEmpty(array $mapped): array
    {
        $properties = [];

        foreach ($mapped as $property => $value) {
            $value = is_scalar($value) ? trim((string)$value) : '';

            if ($value !== '') {
                $properties[$property] = $value;
            }
        }

        return $properties;
    }
}
