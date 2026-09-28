<?php

declare(strict_types=1);

namespace bytesof\formable\web\assets\preview;

use craft\web\AssetBundle;
use craft\web\View;

/**
 * The gallery shell's own chrome - sidebar, frame grid, global controls.
 *
 * Plain CSS and hand-written JS, deliberately outside the Vite pipeline: this
 * is dev-only surface ([[0034-a-devmode-only-preview-gallery]]) with nothing to bundle, minify or
 * ship to a browser that isn't running with `devMode` on.
 *
 * @internal
 */
final class PreviewAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__;

        $this->css = [
            'css/preview.css',
        ];

        $this->js = [
            ['js/preview.js', 'defer' => true],
        ];

        $this->jsOptions = [
            'position' => View::POS_END,
        ];

        parent::init();
    }
}
