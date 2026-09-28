<?php

declare(strict_types=1);

namespace bytesof\formable\web\assets\frontend;

use craft\web\AssetBundle;

/**
 * The `reset` theme level: {@see ContractAsset} plus layout, no visual
 * opinion.
 *
 * For a site building its own design system on top of Formable's markup -
 * the a11y contract plus flex/grid layout and the disabled/submitting state
 * hooks, with no colour, typography or radius to override - plus
 * {@see ContractAsset}'s structural normalize against a host's own unlayered
 * reset. {@see ThemeAsset} loads this same reset alongside the full theme for
 * the `default` level.
 *
 * @internal
 */
final class ResetAsset extends AssetBundle
{
    public function init(): void
    {
        // The commented sources live in `css/`; this is `formable-tokens.css`,
        // `formable-contract.css`, `formable-reset.css` and
        // `formable-hardening.css` concatenated and minified by
        // `scripts/build-css.mjs` (`npm run build:css`, part of `npm run
        // build`), same as `FrontendAsset`'s JS bundle.
        $this->sourcePath = __DIR__ . '/dist';

        $this->css = [
            'formable-reset.css',
        ];

        parent::init();
    }
}
