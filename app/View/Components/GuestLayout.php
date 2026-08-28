<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * The chrome shared by every screen this application renders. Not only sign-in, despite
 * the name it inherited from the Breeze scaffolding: enrollment, recovery codes and the
 * account-security screen all use it while authenticated.
 *
 * The heading is a required prop rather than markup inside each page. It is rendered
 * once, as the page's only `<h1>`, and the document title is derived from it — so the
 * two cannot disagree, and no page can ship without a heading.
 */
class GuestLayout extends Component
{
    public function __construct(
        public string $heading,
        public ?string $subheading = null,
    ) {}

    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {
        return view('layouts.guest');
    }
}
