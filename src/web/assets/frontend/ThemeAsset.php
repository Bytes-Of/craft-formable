<?php

declare(strict_types=1);

namespace bytesof\formable\web\assets\frontend;

use craft\web\AssetBundle;

/**
 * The full form stylesheet - reset plus theme, the `default` theme level.
 *
 * Separate from {@see FrontendAsset}, and on by default since
 * `internal/decisions/0032` - {@see \bytesof\formable\models\Settings::$themeLevel}
 * defaults to `default`, not `none`. `contract` is the documented opt-out down
 * to the accessibility contract alone (`internal/decisions/0062`); `none` is a
 * legacy stored value that now resolves to the same contract-level bundle
 * rather than shipping nothing.
 *
 * {@see ContractAsset} is the a11y contract alone, {@see ResetAsset} is that
 * plus layout, no paint. This bundle loads both alongside the theme so all
 * three never ship out of sync.
 *
 * @internal
 */
final class ThemeAsset extends AssetBundle
{
    public function init(): void
    {
        // The commented sources live in `css/`; this is `formable-tokens.css`,
        // `formable-contract.css`, `formable-reset.css`, `formable-theme.css`
        // and `formable-hardening.css` concatenated and minified by
        // `scripts/build-css.mjs` (`npm run build:css`, part of `npm run
        // build`), same as `FrontendAsset`'s JS bundle.
        $this->sourcePath = __DIR__ . '/dist';

        $this->css = [
            'formable-theme.css',
        ];

        parent::init();
    }
}
