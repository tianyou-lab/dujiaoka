<?php

namespace App\Services;

use App\Settings\ThemeSettings;
use Illuminate\Support\Facades\View;

class ThemeService
{
    protected string $currentTheme;
    protected ThemeSettings $themeSettings;

    public function __construct(ThemeSettings $themeSettings)
    {
        $this->themeSettings = $themeSettings;
        $this->currentTheme = cfg('template', 'morpho');
        $this->registerViews();
    }

    public function getCurrentTheme(): string
    {
        return $this->currentTheme;
    }

    protected function registerViews(): void
    {
        $themePath = resource_path("views/themes/{$this->currentTheme}/views");

        if (is_dir($themePath)) {
            View::addNamespace($this->currentTheme, $themePath);
        }
    }

    /**
     * 获取主题配置值，直接读 ThemeSettings（spatie/laravel-settings 自带缓存）
     */
    public function getConfig(string $key, $default = null)
    {
        return $this->themeSettings->$key ?? $default;
    }

    /**
     * 设置主题配置值
     */
    public function setConfig(string $key, $value): void
    {
        $this->themeSettings->$key = $value;
        $this->themeSettings->save();
    }

    public function asset(string $path): string
    {
        $url = "/assets/{$this->currentTheme}/{$path}";
        return \App\Helpers\CdnHelper::asset($url);
    }
}
