/**
 * Captcha rendering for rendered Formable forms (Pro).
 *
 * The plugin renders only a placeholder; this loads the chosen provider's
 * script and renders the widget into it. Doing it here rather than through the
 * provider's own auto-scan is what lets a captcha appear on an AJAX-swapped
 * step too - an auto-scan runs once on page load and never again.
 *
 * Every provider needs JavaScript to mint a token regardless, so nothing works
 * without this that would have worked otherwise.
 */

/**
 * `size` and `theme` take the same values across all three widget providers
 * (reCAPTCHA v2, hCaptcha, Turnstile) - 'compact'/'normal' and
 * 'light'/'dark', respectively - so one call shape covers all three rather
 * than branching per provider.
 */
interface WidgetApi {
  render(
    container: HTMLElement,
    params: {
      sitekey: string;
      size?: 'compact' | 'normal';
      theme?: 'light' | 'dark';
    },
  ): unknown;
}

interface RecaptchaApi extends WidgetApi {
  ready(callback: () => void): void;
  execute(siteKey: string, options: { action: string }): Promise<string>;
}

interface CaptchaWindow {
  grecaptcha?: RecaptchaApi;
  hcaptcha?: WidgetApi;
  turnstile?: WidgetApi;
}

/** How often to refresh an invisible (v3) token, comfortably inside its ~2min life. */
const V3_REFRESH_MS = 90_000;

/** Below this, a provider's ~300px default widget overflows a narrow sidebar or phone. */
const COMPACT_WIDTH_PX = 310;

/**
 * Reads the resolved colour scheme off the container itself rather than
 * hardcoding `light` - `color-scheme` is an inherited CSS property, so this
 * picks up whatever the token stylesheet last set on an ancestor
 * (`:root`, a site wrapper, or the form's own forced scheme), the same
 * "any ancestor" model `internal/decisions/0070` established for the theme
 * tokens themselves. `auto` (`Settings::$colorScheme`) declares both and
 * leaves the actual choice to the OS, so that's the one case this falls back
 * to `prefers-color-scheme` to resolve.
 */
function resolveColorScheme(container: HTMLElement): 'light' | 'dark' {
  const declared = window.getComputedStyle(container).colorScheme;
  const declaresLight = declared.includes('light');
  const declaresDark = declared.includes('dark');

  if (declaresLight && declaresDark) {
    return window.matchMedia('(prefers-color-scheme: dark)').matches
      ? 'dark'
      : 'light';
  }

  return declaresDark ? 'dark' : 'light';
}

/** One load promise per script URL, so two forms sharing a provider fetch it once. */
const scriptPromises = new Map<string, Promise<void>>();

function captchaWindow(): CaptchaWindow {
  return window as unknown as CaptchaWindow;
}

function loadScript(src: string): Promise<void> {
  const cached = scriptPromises.get(src);

  if (cached) {
    return cached;
  }

  const promise = new Promise<void>((resolve, reject) => {
    if (document.querySelector(`script[src="${src}"]`)) {
      resolve();

      return;
    }

    const script = document.createElement('script');
    script.src = src;
    script.async = true;
    script.defer = true;
    script.addEventListener('load', () => resolve());
    script.addEventListener('error', () =>
      reject(new Error(`Could not load ${src}`)),
    );
    document.head.appendChild(script);
  });

  scriptPromises.set(src, promise);

  return promise;
}

/** Resolves once `check` passes, or rejects after `timeoutMs` - the provider API becoming ready. */
function whenReady(check: () => boolean, timeoutMs = 10_000): Promise<void> {
  return new Promise((resolve, reject) => {
    const start = Date.now();

    const tick = (): void => {
      if (check()) {
        resolve();
      } else if (Date.now() - start > timeoutMs) {
        reject(new Error('Captcha provider did not become ready'));
      } else {
        window.setTimeout(tick, 50);
      }
    };

    tick();
  });
}

/**
 * Renders every not-yet-rendered captcha placeholder under `root`.
 *
 * Idempotent: a placeholder is marked once rendered, so calling this again
 * after a step swap only touches the newly-added one.
 */
export function renderCaptchas(root: ParentNode): void {
  root
    .querySelectorAll<HTMLElement>(
      '[data-formable-captcha]:not([data-formable-captcha-ready])',
    )
    .forEach((container) => {
      renderOne(container).catch((error) => {
        console.warn('[Formable] Captcha could not be rendered', error);
      });
    });
}

async function renderOne(container: HTMLElement): Promise<void> {
  const type = container.dataset.captchaType ?? '';
  const siteKey = container.dataset.captchaSitekey ?? '';
  const scriptUrl = container.dataset.captchaScript ?? '';

  if (siteKey === '' || scriptUrl === '') {
    return;
  }

  // Claim it up front so a second pass (or a re-entrant swap) skips it.
  container.dataset.formableCaptchaReady = 'true';

  await loadScript(scriptUrl);

  if (container.dataset.captchaInvisible !== undefined) {
    await renderInvisible(container, siteKey);

    return;
  }

  await renderWidget(container, type, siteKey);
}

/**
 * reCAPTCHA v3: no widget, a background score. Fetch a token into the hidden
 * input and keep it fresh, since it expires.
 */
async function renderInvisible(
  container: HTMLElement,
  siteKey: string,
): Promise<void> {
  const input = container.querySelector<HTMLInputElement>(
    '[data-formable-captcha-token]',
  );
  const grecaptcha = captchaWindow().grecaptcha;

  await whenReady(
    () => typeof captchaWindow().grecaptcha?.execute === 'function',
  );

  if (!grecaptcha || !input) {
    return;
  }

  const refresh = (): void => {
    grecaptcha.execute(siteKey, { action: 'submit' }).then(
      (token) => {
        input.value = token;
      },
      (error) => console.warn('[Formable] reCAPTCHA execute failed', error),
    );
  };

  grecaptcha.ready(() => {
    refresh();
    window.setInterval(refresh, V3_REFRESH_MS);
  });
}

async function renderWidget(
  container: HTMLElement,
  type: string,
  siteKey: string,
): Promise<void> {
  const widget = document.createElement('div');
  container.appendChild(widget);

  const api = (): WidgetApi | undefined => {
    switch (type) {
      case 'hcaptcha':
        return captchaWindow().hcaptcha;
      case 'turnstile':
        return captchaWindow().turnstile;
      default:
        return captchaWindow().grecaptcha;
    }
  };

  await whenReady(() => typeof api()?.render === 'function');
  api()?.render(widget, {
    sitekey: siteKey,
    size:
      container.getBoundingClientRect().width < COMPACT_WIDTH_PX
        ? 'compact'
        : 'normal',
    theme: resolveColorScheme(container),
  });
}
