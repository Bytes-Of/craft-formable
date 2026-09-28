<?php

declare(strict_types=1);

namespace bytesof\formable\web\assets\frontend;

use craft\web\AssetBundle;
use craft\web\View;

/**
 * The front-end enhancement bundle: AJAX submit, validation feedback and
 * upload previews.
 *
 * Deliberately depends on nothing - a site visitor should never download a
 * framework because the page happens to carry a form. Deferred, since every
 * form works without it.
 *
 * @internal
 */
final class FrontendAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';

        $this->js = [
            ['frontend.js', 'defer' => true],
        ];

        $this->jsOptions = [
            'position' => View::POS_END,
        ];

        parent::init();
    }
}
