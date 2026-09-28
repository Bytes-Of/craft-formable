<?php

declare(strict_types=1);

namespace bytesof\formable\elements\exporters;

use Craft;

/**
 * The same export, with encrypted answers in the clear.
 *
 * It exists as a second exporter rather than a checkbox because Craft's export
 * HUD has no settings surface - an exporter is chosen by class name and given
 * nothing else - so a class is the only place the choice can be made, and
 * {@see \bytesof\formable\elements\Submission::defineExporters()} only offers
 * this one to a user holding `formable:exportSensitive`.
 *
 * The decryption itself is not new; running it deliberately is. A subject
 * access request needs the plaintext, and this is the export that answers one.
 *
 * @internal
 */
final class SensitiveSubmissionExport extends SubmissionExport
{
    public bool $includeEncrypted = true;

    public static function displayName(): string
    {
        return Craft::t('formable', 'Submissions (including encrypted values)');
    }
}
