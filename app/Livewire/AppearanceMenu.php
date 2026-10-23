<?php

namespace App\Livewire;

use App\Enums\Locale;
use App\Enums\Theme;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Topbar controls for the theme and the interface language. Both are saved on the user, then the page reloads
 * because colors, dark mode and the locale are applied while the page is rendered.
 */
class AppearanceMenu extends Component
{
    /**
     * Set on mount from the page being rendered and locked, so the client cannot turn it into an open redirect.
     */
    #[Locked]
    public string $returnUrl = '';

    public function mount(): void
    {
        $this->returnUrl = request()->fullUrl();
    }

    public function setTheme(string $theme): void
    {
        $this->user()->update(['theme' => Theme::tryFrom($theme) ?? abort(422)]);

        $this->redirect($this->returnUrl);
    }

    public function setLocale(string $locale): void
    {
        $this->user()->update(['locale' => Locale::tryFrom($locale) ?? abort(422)]);

        $this->redirect($this->returnUrl);
    }

    public function render(): View
    {
        return view('livewire.appearance-menu', [
            'currentTheme' => $this->user()->theme,
            'currentLocale' => $this->user()->locale,
        ]);
    }

    private function user(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
