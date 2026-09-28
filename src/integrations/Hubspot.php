<?php

declare(strict_types=1);

namespace bytesof\formable\integrations;

use bytesof\formable\base\Crm;
use bytesof\formable\elements\Submission;
use bytesof\formable\models\IntegrationResponse;
use Craft;

/**
 * Creates a HubSpot contact from the submission.
 *
 * Authentication is a Private App access token - HubSpot's no-OAuth path: an
 * admin creates a private app scoped to `crm.objects.contacts.write` and pastes
 * its token here. Form fields are mapped onto standard contact properties.
 *
 * v1 creates the contact outright; a submission from an email HubSpot already
 * holds comes back as a 409 and is logged as a (harmless) failure rather than
 * updating the existing record - property-merge upsert is a later refinement.
 *
 * @internal
 */
final class Hubspot extends Crm
{
    public static function type(): string
    {
        return 'hubspot';
    }

    public static function displayName(): string
    {
        return Craft::t('formable', 'HubSpot');
    }

    public static function description(): string
    {
        return Craft::t('formable', 'Create a HubSpot contact from each submission.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSettingsSchema(): array
    {
        return [
            [
                'name' => 'accessToken',
                'type' => 'text',
                'secret' => true,
                'label' => Craft::t('formable', 'Private App Token'),
                'instructions' => Craft::t('formable', 'From HubSpot under Settings → Integrations → Private Apps. Needs the {scope} scope.', [
                    'scope' => 'crm.objects.contacts.write',
                ]),
                'required' => true,
            ],
        ];
    }

    /**
     * @return array<int, array{handle: string, name: string, required?: bool}>
     */
    public function getMappableFields(): array
    {
        return [
            ['handle' => 'email', 'name' => Craft::t('formable', 'Email'), 'required' => true],
            ['handle' => 'firstname', 'name' => Craft::t('formable', 'First Name')],
            ['handle' => 'lastname', 'name' => Craft::t('formable', 'Last Name')],
            ['handle' => 'phone', 'name' => Craft::t('formable', 'Phone Number')],
            ['handle' => 'company', 'name' => Craft::t('formable', 'Company Name')],
            ['handle' => 'jobtitle', 'name' => Craft::t('formable', 'Job Title')],
            ['handle' => 'website', 'name' => Craft::t('formable', 'Website')],
        ];
    }

    public function isConfigured(): bool
    {
        return $this->env('accessToken') !== '';
    }

    public function send(Submission $submission, array $mapped): IntegrationResponse
    {
        if (!$this->isConfigured()) {
            return IntegrationResponse::failed(Craft::t('formable', 'No HubSpot access token is set.'));
        }

        $properties = $this->nonEmpty($mapped);

        if (!isset($properties['email'])) {
            return IntegrationResponse::failed(Craft::t('formable', 'The mapped email property was empty.'));
        }

        return $this->request(
            'POST',
            'https://api.hubapi.com/crm/v3/objects/contacts',
            ['properties' => $properties],
            $this->authHeaders(),
        );
    }

    public function checkConnection(): IntegrationResponse
    {
        if (!$this->isConfigured()) {
            return IntegrationResponse::failed(Craft::t('formable', 'Enter an access token first.'));
        }

        return $this->request(
            'GET',
            'https://api.hubapi.com/crm/v3/objects/contacts?limit=1',
            [],
            $this->authHeaders(),
        );
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->env('accessToken')];
    }
}
