<?php

namespace Spawn\Laravel\Translation;

use Illuminate\Translation\Translator;

use function Async\current_context;

/**
 * Coroutine-safe Translator.
 *
 * Before bootCompleted(): behaves like stock Translator.
 * After bootCompleted(): $locale is stored per-coroutine in current_context().
 *
 * The $loaded translations cache remains shared — translations are the same
 * for all requests, only the active locale differs per coroutine.
 */
class AsyncTranslator extends Translator
{
    private const CTX_KEY = 'translator.locale';

    private bool $async = false;

    private string $bootLocale;

    public function bootCompleted(): void
    {
        $this->bootLocale = $this->locale;
        $this->async = true;
    }

    public function setLocale($locale)
    {
        if (! $this->async) {
            parent::setLocale($locale);
            return;
        }

        if (str_contains($locale, '/') || str_contains($locale, '\\')) {
            throw new \InvalidArgumentException('Invalid characters present in locale.');
        }

        current_context()->set(self::CTX_KEY, $locale, replace: true);
    }

    public function getLocale()
    {
        if (! $this->async) {
            return parent::getLocale();
        }

        return current_context()->find(self::CTX_KEY) ?? $this->bootLocale;
    }

    public function locale()
    {
        return $this->getLocale();
    }

    /**
     * Override to use getLocale() instead of $this->locale.
     */
    public function get($key, array $replace = [], $locale = null, $fallback = true)
    {
        if ($this->async && $locale === null) {
            $locale = $this->getLocale();
        }

        return parent::get($key, $replace, $locale, $fallback);
    }

    public function choice($key, $number, array $replace = [], $locale = null)
    {
        if ($this->async && $locale === null) {
            $locale = $this->getLocale();
        }

        return parent::choice($key, $number, $replace, $locale);
    }

    public function has($key, $locale = null, $fallback = true)
    {
        if ($this->async && $locale === null) {
            $locale = $this->getLocale();
        }

        return parent::has($key, $locale, $fallback);
    }

    protected function localeForChoice($key, $locale)
    {
        if ($this->async && $locale === null) {
            $locale = $this->getLocale();
        }

        return parent::localeForChoice($key, $locale);
    }

    protected function localeArray($locale)
    {
        if ($this->async && $locale === null) {
            $locale = $this->getLocale();
        }

        return parent::localeArray($locale);
    }
}
