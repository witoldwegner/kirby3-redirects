<?php

declare(strict_types=1);

namespace Bnomei;

use Closure;
use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Kirby\Content\Field;
use Kirby\Data\Yaml;
use Kirby\Http\Header;
use Kirby\Http\Url;
use Kirby\Toolkit\A;

use function option;

class Redirects
{
    private array $options;

    public function __construct(array $options = [])
    {
        $this->options = array_merge([
            'code' => option('bnomei.redirects.code'),
            'querystring' => option('bnomei.redirects.querystring'),
            'map' => option('bnomei.redirects.map'),
            'parent' => null, // will be set by loadRedirectsFromSource
            'shield.enabled' => option('bnomei.redirects.shield.enabled'),
            'shield.generic' => option('bnomei.redirects.shield.generic'),
            'shield.wordpress' => option('bnomei.redirects.shield.wordpress'),
            'shield.joomla' => option('bnomei.redirects.shield.joomla'),
            'shield.drupal' => option('bnomei.redirects.shield.drupal'),
            'shield.magento' => option('bnomei.redirects.shield.magento'),
            'shield.shopify' => option('bnomei.redirects.shield.shopify'),
            'site.url' => kirby()->url(), // a) www.example.com or b) www.example.com/subfolder, NOT site()->url() as that contains the language code
            'request.uri' => strval(A::get($options, 'request.uri', $this->getRequestURI())),
        ], $options);

        foreach ($this->options as $key => $call) {
            if ($call instanceof Closure && in_array($key, ['code', 'querystring', 'map'])) {
                $this->options[$key] = $call();
            }
        }

        // make sure the request.uri starts with a /
        $this->options['request.uri'] = '/' . ltrim($this->options['request.uri'], '/');

        $this->loadRedirectsFromSource($this->options['map']);
        $this->addShieldToRedirects();
        $this->buildLookup();
        // keep map around to allow update/removes
        // $this->options['map'] = null; // NOPE!
    }

    public function option(?string $key = null): mixed
    {
        if ($key) {
            return A::get($this->options, $key);
        }

        return $this->options;
    }

    public function loadRedirectsFromSource(array|Field|null $source = null): array
    {
        $this->options['parent'] = null;

        if ($source instanceof Field) {
            $this->options['parent'] = $source->parent();
            // https://getkirby.com/docs/reference/templates/field-methods/yaml
            $source = $source->isNotEmpty() ? $source->yaml() : []; // @phpstan-ignore-line
        }

        $this->options['redirects'] = is_array($source) ? $source : [];

        return $this->options['redirects'];
    }

    private function addShieldToRedirects(): array
    {
        if ($this->options['shield.enabled'] !== true) {
            return $this->options['redirects'];
        }
        // array_merge is not working properly here, so we do it manually
        foreach (
            [
                'shield.generic',
                'shield.wordpress',
                'shield.joomla',
                'shield.drupal',
                'shield.magento',
                'shield.shopify',
            ] as $shield
        ) {
            foreach ($this->options[$shield] as $redirect) {
                $this->options['redirects'][] = $redirect;
            }
        }

        return $this->options['redirects'];
    }

    private function buildLookup(): array
    {
        // Keep all redirects (don't collapse by fromuri) to support domain-specific overrides
        // Sort domain-specific redirects before global ones so scoped matches take precedence
        $redirects = $this->options['redirects'];
        
        usort($redirects, function ($a, $b) {
            $domainA = ! empty(trim(A::get($a, 'domain', '')));
            $domainB = ! empty(trim(A::get($b, 'domain', '')));
            
            // Sort domain-specific (true) before global (false)
            // In usort: negative = $a first, positive = $b first, 0 = equal
            if ($domainA === $domainB) {
                return 0; // maintain order for same priority
            }
            return $domainA ? -1 : 1; // domain-specific first
        });
        
        $this->options['redirects'] = $redirects;
        return $this->options['redirects'];
    }

    public function redirects(): array
    {
        return (array) $this->options['redirects'];
    }

    public function append(array $change): bool
    {
        // wrap single change in array of changes
        if (count($change) === count($change, COUNT_RECURSIVE)) {
            $change = [$change];
        }

        $code = $this->option('code');
        $change = array_map(function ($v) use ($code) {
            $redirect = new Redirect(
                A::get($v, 'fromuri'),
                A::get($v, 'touri'),
                A::get($v, 'code', $code)
            );

            return $redirect->toArray();
        }, $change);

        $data = array_merge($this->redirects(), $change);
        $this->options['redirects'] = $data;

        return $this->updateRedirects($data);
    }

    public function remove(array $change): bool
    {
        // wrap single change in array of changes
        if (count($change) === count($change, COUNT_RECURSIVE)) {
            $change = [$change];
        }

        $data = $this->redirects();
        $copy = $data;
        foreach ($change as $item) {
            foreach ($copy as $key => $redirect) {
                if (
                    A::get($redirect, 'fromuri') === A::get($item, 'fromuri') &&
                    A::get($redirect, 'touri') === A::get($item, 'touri') &&
                    A::get($redirect, 'domain') === A::get($item, 'domain')
                ) {
                    unset($data[$key]);
                    break; // exit inner loop
                }
            }
        }
        $this->options['redirects'] = $data;

        return $this->updateRedirects($data);
    }

    public function sortAndUpdate(): bool
    {
        $r = $this->redirects();
        $r = A::sort($r, 'fromuri', 'asc');

        return $this->updateRedirects($r);
    }

    public function updateRedirects(array $data): bool
    {
        $parent = $this->getParent();
        if (! $parent) {
            return false;
        }

        // retrieve again for mutability
        if ($parent instanceof Site) {
            $parent = kirby()->site();
        } else {
            $parent = kirby()->page($parent->id());
        }

        return (bool) kirby()->impersonate('kirby', function () use ($parent, $data) {
            /** @var Field $map */
            $map = $this->option('map');
            $fieldKey = $map->key();
            // @codeCoverageIgnoreStart
            $parent->update([
                $fieldKey => Yaml::encode($data),
            ]);
            // @codeCoverageIgnoreEnd

            // static::flush(); // the hook will do this anyway
            return true;
        });
    }

    // getter function for parent value $option
    public function getParent(): Page|Site|null
    {
        return $this->options['parent'];
    }

    public static function isKnownValidRoute(string $path, string $domain = ''): bool
    {
        $cacheKey = md5($path . $domain);
        return kirby()->cache('bnomei.redirects')->get($cacheKey) !== null;
    }

    public static function flush(): bool
    {
        return kirby()->cache('bnomei.redirects')->flush();
    }

    public function checkForRedirect(?string $uri = null): ?Redirect
    {
        $requesturi = $uri ?? (string) $this->options['request.uri'];
        $currentDomain = $this->getCurrentDomainIdentifier();
        $cacheKey = md5($requesturi . $currentDomain);
        
        if (static::isKnownValidRoute($requesturi, $currentDomain)) {
            return null;
        }

        $map = $this->redirects();
        if (count($map) === 0) {
            return null;
        }

        $r = new Redirect;
        // Collect all redirects for this path (not just first match)
        $candidates = [];
        foreach ($map as $redirect) {
            if (A::get($redirect, 'fromuri') === $requesturi) {
                $candidates[] = $redirect;
            }
        }
        // If no direct matches, check all redirects
        if (empty($candidates)) {
            $candidates = $map;
        }

        foreach ($candidates as $redirect) {
            if (
                ! array_key_exists('fromuri', $redirect) ||
                ! array_key_exists('touri', $redirect)
            ) {
                continue;
            }

            // Check if this redirect applies to the current domain
            if (! $this->isDomainApplicable($redirect, $currentDomain)) {
                continue;
            }

            $r = $r->set(
                $this->makeRelativePath(A::get($redirect, 'fromuri', '')),
                A::get($redirect, 'touri', ''),
                A::get($redirect, 'code', $this->option('code'))
            );

            if ($r->matches($requesturi)) {
                return $r;
            }
        }

        // no redirect found, flag as valid route per domain
        // so it is not checked again until the cache is flushed
        kirby()->cache('bnomei.redirects')->set($cacheKey, [
            $requesturi,
            $currentDomain,
        ]);

        return null;
    }

    /**
     * Check if a redirect applies to the current domain
     * 
     * @param array $redirect The redirect configuration
     * @param string $currentDomain The current domain identifier (e.g., 'polizei-einstellungstest', 'ausbildungspark')
     * @return bool True if redirect should be applied, false otherwise
     */
    private function isDomainApplicable(array $redirect, string $currentDomain): bool
    {
        $domain = A::get($redirect, 'domain', '');

        // If no domain is specified (empty), this redirect applies globally
        if (empty(trim($domain))) {
            return true;
        }

        // Check if the current domain contains the specified domain identifier
        return str_contains($currentDomain, trim($domain));
    }

    /**
     * Get the current domain identifier from the request hostname
     * 
     * Examples:
     * - www.polizei-einstellungstest.de → 'polizei-einstellungstest'
     * - polizei-einstellungstest-de.ww → 'polizei-einstellungstest'
     * - www.ausbildungspark.com → 'ausbildungspark'
     * - ausbildungspark-de.ww → 'ausbildungspark'
     * 
     * @return string The domain identifier
     */
    private function getCurrentDomainIdentifier(): string
    {
        try {
            $host = kirby()->request()->url()->host();
        } catch (\Exception $e) {
            // Fallback to site.url if host detection fails
            $siteurl = A::get($this->options, 'site.url', '');
            if (! empty($siteurl)) {
                $host = parse_url($siteurl, PHP_URL_HOST) ?? '';
                if (empty($host)) {
                    return '';
                }
            } else {
                return '';
            }
        }

        // Remove www prefix if present
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        // Remove TLD/domain suffix (everything after first dot)
        $parts = explode('.', $host);
        $domain = $parts[0];

        // If domain contains hyphens followed by language code (e.g., -de, -en), remove that part
        // Match pattern: -xx where xx is 1-3 chars (typical language codes)
        $domain = preg_replace('/-[a-z]{1,3}$/', '', $domain);

        return $domain;
    }

    private function makeRelativePath(string $url): string
    {
        $siteurl = A::get($this->options, 'site.url');
        $sitebase = Url::path($siteurl, true, true);
        $url = $siteurl !== '/' ? str_replace($siteurl, '', $url) : $url;

        return '/' . trim($sitebase . $url, '/');
    }

    private function getRequestURI(): string
    {
        $uri = array_key_exists('REQUEST_URI', $_SERVER) ? strval($_SERVER['REQUEST_URI']) : kirby()->request()->path()->toString(leadingSlash: true);
        $uri = option('bnomei.redirects.querystring') ? $uri : strtok($uri, '?'); // / or /page or /subfolder or /subfolder/page

        return $uri !== false ? $uri : '';
    }

    public function redirect(): void
    {
        $redirect = $this->checkForRedirect();
        if (! $redirect) {
            return;
        }

        $code = $redirect->code();
        kirby()->trigger('redirect:before', ['code' => $code, 'redirect' => $redirect]);

        // @codeCoverageIgnoreStart
        if ($code >= 300 && $code < 400) {
            Header::redirect(Redirect::url($redirect->to()), $code);
        } else {
            Header::status($code);
            exit();
        }
        // @codeCoverageIgnoreEnd
    }

    private static ?self $singleton = null;

    public static function singleton(array $options = []): self
    {
        // @codeCoverageIgnoreStart
        if (self::$singleton === null) {
            self::$singleton = new self($options);
        }
        // @codeCoverageIgnoreEnd

        return self::$singleton;
    }

    public static function codes(bool $force = false): ?array
    {
        // NOTE: do not use a cache in this method as it is
        // called in the panel php blueprint and the cache
        // is not available there yet. => NullCache issue

        // $cache = kirby()->cache('bnomei.redirects');
        // $codes = null;
        // if (! $force && ! option('debug')) {
        //     $codes = $cache->get('httpcodes');
        // }
        // if ($codes) {
        //     return $codes;
        // }

        $codes = [];
        foreach (Header::$codes as $code => $label) {
            $codes[] = [
                'code' => $code, // string: _302
                'label' => $label,
            ];
        }
        // $cache->set('httpcodes', $codes, 60 * 24 * 7);

        return $codes;
    }

    public static array $cache = [];

    public static function staticCache(string $key, Closure $closure): mixed
    {
        if ($value = A::get(self::$cache, $key, null)) {
            return $value;
        }

        self::$cache[$key] = $closure();

        return self::$cache[$key];
    }
}
