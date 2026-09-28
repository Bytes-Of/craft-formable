<?php

declare(strict_types=1);

namespace bytesof\formable\elements\exporters;

use bytesof\formable\base\FormField;
use bytesof\formable\elements\Submission;
use Craft;
use craft\base\ElementExporter;
use craft\elements\db\ElementQueryInterface;
use craft\web\Request as WebRequest;

/**
 * Exports submissions as flat rows - one per submission, a column per field -
 * which Craft then serves as CSV, JSON or XML from the index's export HUD.
 *
 * The field columns are the union across every submission in the result, so a
 * single-form export is tidy and a mixed-form one still lines up, each row
 * leaving blank the columns whose field it doesn't have.
 *
 * A field marked *Encrypt value* is masked here, and included only by
 * {@see SensitiveSubmissionExport} - the same rule the submission title and the
 * search index already follow, applied to the one place that used to decrypt
 * without being asked to.
 *
 * @internal
 */
class SubmissionExport extends ElementExporter
{
    /**
     * What an encrypted field's answer reads as when it isn't being exported.
     *
     * A mask rather than a blank: the column is still there, and a blank would
     * say the question went unanswered.
     */
    public const MASK = '••••••';

    /**
     * Whether encrypted answers are exported in the clear.
     *
     * Off here, which is the export any user with `formable:viewSubmissions`
     * can run. {@see SensitiveSubmissionExport} is the one that turns it on,
     * and it is only offered to a user with `formable:exportSensitive`.
     */
    public bool $includeEncrypted = false;

    public static function displayName(): string
    {
        return Craft::t('formable', 'Submissions');
    }

    /**
     * @param ElementQueryInterface $query
     * @return array<int, array<string, mixed>>
     */
    public function export(ElementQueryInterface $query): array
    {
        /** @var Submission[] $submissions */
        $submissions = $query->all();

        // First pass: the ordered set of field columns, keyed by handle so two
        // forms sharing a handle share a column, labelled by the first label seen.
        $columns = [];

        foreach ($submissions as $submission) {
            foreach ($submission->getFormFields() as $handle => $field) {
                if (!isset($columns[$handle])) {
                    $columns[$handle] = $field->label !== '' ? $field->label : $handle;
                }
            }
        }

        $rows = [];

        // Carried only where it distinguishes anything - the same reasoning as
        // the index column, and it keeps a single-site export byte-identical to
        // the one people already have scripts pointed at.
        $multiSite = Craft::$app->getIsMultiSite();

        foreach ($submissions as $submission) {
            $fields = $submission->getFormFields();

            $row = [
                Craft::t('app', 'ID') => $submission->id,
                Craft::t('formable', 'Form') => $submission->getForm()?->title,
                Craft::t('formable', 'Status') => $submission->getStatusModel()?->name,
                Craft::t('formable', 'Submitted by') => $submission->getUser()?->getName(),
            ];

            if ($multiSite) {
                $row[Craft::t('formable', 'Submitted from')] = $submission->getSubmittedSite()?->getName();
            }

            $row += [
                Craft::t('formable', 'IP Address') => $submission->ipAddress,
                Craft::t('app', 'Date Created') => $submission->dateCreated?->format('c'),
            ];

            foreach ($columns as $handle => $label) {
                $field = $fields[$handle] ?? null;
                $row[$label] = $field !== null
                    ? $this->cell($submission, $field, $handle)
                    : '';
            }

            $rows[] = $row;
        }

        return $this->isCsv() ? array_map($this->defuseRow(...), $rows) : $rows;
    }

    /**
     * Whether the file being written is a CSV.
     *
     * Craft picks the format after the exporter is chosen and never tells it, so
     * the request is the only place to look; `csv` is Craft's own default when
     * none is sent, and the right assumption for anything without a request.
     */
    private function isCsv(): bool
    {
        $request = Craft::$app->getRequest();

        if (!$request instanceof WebRequest) {
            return true;
        }

        return $request->getBodyParam('format', 'csv') === 'csv';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function defuseRow(array $row): array
    {
        $defused = [];

        // Labels too: an imported form's field label is text from outside.
        foreach ($row as $label => $value) {
            $defused[self::defuse((string) $label)] = is_string($value) ? self::defuse($value) : $value;
        }

        return $defused;
    }

    /**
     * Stops a spreadsheet reading a cell as a formula.
     *
     * A visitor's answer is untrusted text that an admin opens in Excel or
     * Sheets, and a cell beginning `=`, `+`, `-` or `@` - or a tab or carriage
     * return, which some versions skip past to find one - is run as a formula.
     * A leading apostrophe makes the cell text; spreadsheets hide it and it
     * survives a round trip as the literal it is.
     *
     * A plain number such as `-5` or `+447700900123` is left alone: it is a
     * value rather than an expression, and quoting it would turn a numeric
     * column into text.
     */
    public static function defuse(string $value): string
    {
        if ($value === '' || !str_contains("=+-@\t\r", $value[0])) {
            return $value;
        }

        if (preg_match('/^[+-]?\d+(\.\d+)?$/', $value) === 1) {
            return $value;
        }

        return "'" . $value;
    }

    /**
     * One answer as it should appear in the file.
     *
     * An export is a plaintext file that leaves Craft's access controls behind
     * the moment it is downloaded, so an encrypted answer is masked unless the
     * export was deliberately asked for it. An answer that is empty stays
     * empty: masking one would claim data the submission doesn't hold.
     */
    private function cell(Submission $submission, FormField $field, string $handle): string
    {
        $string = $field->valueToString($submission->getValue($handle));

        if ($string === '' || !$field->encrypted || $this->includeEncrypted) {
            return $string;
        }

        return self::MASK;
    }
}
