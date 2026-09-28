<?php

declare(strict_types=1);

namespace bytesof\formable\controllers;

use bytesof\formable\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\Response;

/**
 * Backs the "Send test" button on the plugin settings screen.
 *
 * Admin-only, matching the settings screen it lives on. It sends a test digest
 * to the address the admin types in - or, left blank, to the configured
 * recipient list - without touching the weekly schedule.
 *
 * @internal
 */
final class DigestController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireAdmin();

        return true;
    }

    public function actionTest(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $notifications = Plugin::getInstance()->getNotifications();
        $settings = Plugin::getInstance()->getSettings();

        // A typed address wins; blank falls back to the saved recipient list, so
        // the button works before the setting is even saved.
        $typed = trim((string)$this->request->getBodyParam('to', ''));
        $to = $typed !== ''
            ? $notifications->parseAddressList($typed)
            : $notifications->parseAddressList($settings->weeklyDigestRecipients);

        if ($to === []) {
            return $this->asJson([
                'success' => false,
                'message' => Craft::t('formable', 'Enter a valid email address, or add recipients above first.'),
            ]);
        }

        if (!Plugin::getInstance()->getDigest()->sendTest($to)) {
            return $this->asJson([
                'success' => false,
                'message' => Craft::t('formable', 'The test digest could not be sent. Check your email settings and the logs.'),
            ]);
        }

        return $this->asJson([
            'success' => true,
            'message' => Craft::t('formable', 'Test digest sent to {recipients}.', [
                'recipients' => implode(', ', $to),
            ]),
        ]);
    }
}
