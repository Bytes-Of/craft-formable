<?php

declare(strict_types=1);

namespace bytesof\formable\web\assets\formbuilder;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use craft\web\View;

/**
 * The Vue form builder bundle, published from the Vite build output.
 *
 * @internal
 */
final class FormBuilderAsset extends AssetBundle
{
    /**
     * Hands the builder's strings to Craft's JS translation runtime, so the Vue
     * sources can call `Craft.t('formable', …)` the way any CP script does.
     *
     * The list is generated from the sources by `scripts/builder-i18n.mjs` -
     * see strings.php - rather than maintained here, because a hand-kept map is
     * exactly what drifted out of sync with the components before.
     *
     * @param View $view
     */
    public function registerAssetFiles($view): void
    {
        if ($view instanceof View) {
            /** @var string[] $messages */
            $messages = require __DIR__ . '/strings.php';

            $view->registerTranslations('formable', $messages);
        }

        parent::registerAssetFiles($view);
    }

    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';

        $this->depends = [
            CpAsset::class,
        ];

        $this->js = [
            'formbuilder.js',
        ];

        $this->css = [
            'formbuilder.css',
        ];

        parent::init();
    }
}
