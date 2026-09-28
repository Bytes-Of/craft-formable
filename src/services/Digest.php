<?php

declare(strict_types=1);

namespace bytesof\formable\services;

use bytesof\formable\elements\Form;
use bytesof\formable\elements\Submission;
use bytesof\formable\Plugin;
use Craft;
use craft\helpers\Db;
use craft\web\View;
use DateInterval;
use DateTime;
use DateTimeInterface;
use Throwable;
use yii\base\Component;
use yii\mail\MessageInterface;

/**
 * The weekly activity digest (Pro): a Twig-rendered email summarising each
 * form's new submissions and spam counts over the trailing seven days, sent to
 * a fixed recipient list on a schedule the plugin owns.
 *
 * The schedule question - is a digest due right now - is answered purely by
 * {@see \bytesof\formable\models\Settings::isDigestDue()}; this service holds the
 * edition gate, the counting, the compose-and-send, and the once-a-week guard's
 * durable "last sent" marker. The send itself is driven off the request path by
 * the `formable/digest/run` console command wired to the server's cron.
 *
 * @internal
 */
final class Digest extends Component
{
    /**
     * The trailing window a digest reports on, in days.
     */
    public const WINDOW_DAYS = 7;

    /**
     * Cache key for the timestamp of the last digest that went out. Cache
     * rather than project config: it changes every week and must not churn a
     * deployable config file. A cleared cache at worst lets one extra digest
     * through, which the resend guard in {@see Settings::isDigestDue()} still
     * bounds.
     */
    private const LAST_SENT_CACHE_KEY = 'formable.digest.lastSent';

    /**
     * Runs the scheduled digest: sends if one is due, and records the send so
     * the same week can't fire twice. The cron entry point.
     *
     * Returns null when nothing was due (the common case, since the cron runs
     * far more often than weekly), or the boolean result of the attempt.
     */
    public function run(?DateTimeInterface $now = null): ?bool
    {
        if (!Plugin::getInstance()->isPro()) {
            return null;
        }

        $now ??= new DateTime('now');

        if (!Plugin::getInstance()->getSettings()->isDigestDue($now, $this->getLastSent())) {
            return null;
        }

        $sent = $this->send(false, $now);

        // Mark the week handled whether or not an email actually went out - a
        // week skipped for having no activity is still a week we've dealt with,
        // and must not be retried on the next cron tick within the hour.
        $this->recordSent($now);

        return $sent;
    }

    /**
     * Composes and sends the digest to the configured recipients now.
     *
     * A skipped-because-empty week counts as a successful no-op (returns true)
     * so the caller records it and moves on; a genuine failure (no recipients,
     * mailer error) returns false. `$force` sends even an empty digest, for the
     * test-send button and a manual console send.
     */
    public function send(bool $force = false, ?DateTimeInterface $now = null): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        $to = Plugin::getInstance()->getNotifications()->parseAddressList($settings->weeklyDigestRecipients);

        if ($to === []) {
            Craft::warning('Skipping the Formable weekly digest: no valid recipients configured.', __METHOD__);

            return false;
        }

        [$since, $until] = $this->window($now);
        $stats = $this->getStats($since, $until);

        if (!$force && $settings->weeklyDigestSkipWhenEmpty && !$this->hasActivity($stats)) {
            return true;
        }

        try {
            return $this->compose($to, $stats)->send();
        } catch (Throwable $e) {
            Craft::error(
                sprintf('Could not send the Formable weekly digest: %s', $e->getMessage()),
                __METHOD__,
            );

            return false;
        }
    }

    /**
     * Sends a `[Test]` copy of this week's digest to the given addresses,
     * regardless of schedule, activity, or edition - the settings screen's
     * "Send test" button. Never touches the last-sent marker.
     *
     * @param array<int, string> $to
     */
    public function sendTest(array $to): bool
    {
        if ($to === []) {
            return false;
        }

        [$since, $until] = $this->window();

        try {
            $message = $this->compose($to, $this->getStats($since, $until), true);

            return $message->send();
        } catch (Throwable $e) {
            Craft::error(
                sprintf('Could not send a Formable test digest: %s', $e->getMessage()),
                __METHOD__,
            );

            return false;
        }
    }

    /**
     * Per-form activity for the window, plus totals.
     *
     * Only forms with something to report (a submission or a flagged spam)
     * appear under `forms`; the totals count across all of them. Counts match
     * the submission-limit gate's definition of a real submission - completed,
     * not incomplete - with spam split out rather than dropped.
     *
     * On a multi-site install the same window is also broken down by the site
     * each submission came from, under `sites` - which is the whole point of
     * recording it, and the one place a per-site view costs nothing to offer.
     * Single-site installs get an empty list and the email drops the table.
     *
     * @return array{since: DateTimeInterface, until: DateTimeInterface, forms: array<int, array{form: Form, submissions: int, spam: int}>, sites: array<int, array{name: string, submissions: int, spam: int}>, totalSubmissions: int, totalSpam: int}
     */
    public function getStats(DateTimeInterface $since, DateTimeInterface $until): array
    {
        $forms = [];
        $totalSubmissions = 0;
        $totalSpam = 0;

        foreach (Plugin::getInstance()->getForms()->getAllForms() as $form) {
            if ($form->id === null) {
                continue;
            }

            $submissions = $this->countSubmissions($form->id, $since, $until, false);
            $spam = $this->countSubmissions($form->id, $since, $until, true);

            $totalSubmissions += $submissions;
            $totalSpam += $spam;

            if ($submissions > 0 || $spam > 0) {
                $forms[] = [
                    'form' => $form,
                    'submissions' => $submissions,
                    'spam' => $spam,
                ];
            }
        }

        return [
            'since' => $since,
            'until' => $until,
            'forms' => $forms,
            'sites' => $this->getSiteStats($since, $until),
            'totalSubmissions' => $totalSubmissions,
            'totalSpam' => $totalSpam,
        ];
    }

    /**
     * The same window, counted by the site each submission arrived through.
     *
     * Only sites with something to report appear, and only on a multi-site
     * install. Submissions with no site recorded - anything stored before the
     * column existed, or made with no current site - are grouped under a single
     * unattributed row rather than dropped, so the breakdown still adds up to
     * the totals above it.
     *
     * @return array<int, array{name: string, submissions: int, spam: int}>
     */
    private function getSiteStats(DateTimeInterface $since, DateTimeInterface $until): array
    {
        if (!Craft::$app->getIsMultiSite()) {
            return [];
        }

        $rows = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $submissions = $this->countBySite($site->id, $since, $until, false);
            $spam = $this->countBySite($site->id, $since, $until, true);

            if ($submissions > 0 || $spam > 0) {
                $rows[] = [
                    'name' => Craft::t('site', $site->getName()),
                    'submissions' => $submissions,
                    'spam' => $spam,
                ];
            }
        }

        $unattributed = $this->countBySite(null, $since, $until, false);
        $unattributedSpam = $this->countBySite(null, $since, $until, true);

        if ($unattributed > 0 || $unattributedSpam > 0) {
            $rows[] = [
                'name' => Craft::t('formable', 'Unknown'),
                'submissions' => $unattributed,
                'spam' => $unattributedSpam,
            ];
        }

        return $rows;
    }

    /**
     * Whether a stats set has anything worth emailing about.
     *
     * @param array{totalSubmissions: int, totalSpam: int} $stats
     */
    public function hasActivity(array $stats): bool
    {
        return $stats['totalSubmissions'] > 0 || $stats['totalSpam'] > 0;
    }

    /**
     * The moment the last digest went out, or null if none has.
     */
    public function getLastSent(): ?DateTime
    {
        $timestamp = Craft::$app->getCache()->get(self::LAST_SENT_CACHE_KEY);

        if (!is_numeric($timestamp)) {
            return null;
        }

        return (new DateTime())->setTimestamp((int)$timestamp);
    }

    /**
     * Records that a digest went out at the given moment.
     */
    public function recordSent(DateTimeInterface $when): void
    {
        // No TTL - the marker must outlive any cache-duration default, and the
        // resend guard keys off its age, not its presence.
        Craft::$app->getCache()->set(self::LAST_SENT_CACHE_KEY, $when->getTimestamp(), 0);
    }

    /**
     * The `[since, until)` window a digest run reports on: the last
     * {@see WINDOW_DAYS} up to now.
     *
     * @return array{0: DateTimeInterface, 1: DateTimeInterface}
     */
    private function window(?DateTimeInterface $now = null): array
    {
        $until = $now !== null
            ? (new DateTime())->setTimestamp($now->getTimestamp())
            : new DateTime('now');
        $since = (clone $until)->sub(new DateInterval('P' . self::WINDOW_DAYS . 'D'));

        return [$since, $until];
    }

    /**
     * Counts completed submissions of one kind (spam or not) in the window.
     */
    private function countSubmissions(int $formId, DateTimeInterface $since, DateTimeInterface $until, bool $spam): int
    {
        return (int)Submission::find()
            ->formId($formId)
            ->isIncomplete(false)
            ->isSpam($spam)
            ->status(null)
            ->andWhere(['>=', 'formable_submissions.dateCreated', Db::prepareDateForDb($since)])
            ->andWhere(['<', 'formable_submissions.dateCreated', Db::prepareDateForDb($until)])
            ->count();
    }

    /**
     * Counts completed submissions of one kind that came in through one site,
     * across every form. A null site counts the unattributed ones.
     */
    private function countBySite(?int $siteId, DateTimeInterface $since, DateTimeInterface $until, bool $spam): int
    {
        return (int)Submission::find()
            ->submittedSiteId($siteId ?? ':empty:')
            ->isIncomplete(false)
            ->isSpam($spam)
            ->status(null)
            ->andWhere(['>=', 'formable_submissions.dateCreated', Db::prepareDateForDb($since)])
            ->andWhere(['<', 'formable_submissions.dateCreated', Db::prepareDateForDb($until)])
            ->count();
    }

    /**
     * Builds the digest email: the shared mailer, the plugin's sender settings,
     * and a Twig-rendered HTML body.
     *
     * @param array<int, string> $to
     * @param array{since: DateTimeInterface, until: DateTimeInterface, forms: array<int, array{form: Form, submissions: int, spam: int}>, sites: array<int, array{name: string, submissions: int, spam: int}>, totalSubmissions: int, totalSpam: int} $stats
     */
    private function compose(array $to, array $stats, bool $test = false): MessageInterface
    {
        $siteName = Craft::$app->getSites()->getCurrentSite()->getName();

        $subject = Craft::t('formable', 'Formable weekly digest - {site}', ['site' => $siteName]);

        if ($test) {
            $subject = Craft::t('formable', '[Test] {subject}', ['subject' => $subject]);
        }

        $body = $this->renderBody($stats + ['siteName' => $siteName]);

        $message = Craft::$app->getMailer()->compose();
        $message->setTo($to);
        $message->setSubject($subject);
        $message->setHtmlBody($body);

        $from = $this->resolveFrom();

        if ($from !== null) {
            $message->setFrom($from);
        }

        return $message;
    }

    /**
     * Renders the digest template in site mode, so it resolves through the same
     * template root the front-end pack uses and works from a console run.
     *
     * @param array<string, mixed> $variables
     */
    private function renderBody(array $variables): string
    {
        $view = Craft::$app->getView();
        $templateMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_SITE);

        try {
            return $view->renderTemplate('formable/email/digest', $variables);
        } finally {
            $view->setTemplateMode($templateMode);
        }
    }

    /**
     * The From address for the digest, from the digest sender settings, or null
     * to use Craft's system default. Notifications are addressed separately,
     * through Craft's own mail settings - this pair is the digest's alone.
     *
     * @return array<string, string>|string|null
     */
    private function resolveFrom(): array|string|null
    {
        $settings = Plugin::getInstance()->getSettings();
        $email = trim($settings->senderEmail);

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        $name = trim($settings->senderName);

        return $name !== '' ? [$email => $name] : $email;
    }
}
