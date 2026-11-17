<?php

namespace Jnrflsh\ModuleLoader\Tests\Fixtures\Modules\legacyFeed\View\Components;

use Illuminate\View\Component;

/**
 * Lives in a directory whose name is not StudlyCase, so its namespace segment
 * must match the directory verbatim to autoload on a case-sensitive filesystem.
 */
class Notice extends Component
{
    public function render(): string
    {
        return '<p class="notice">{{ $slot }}</p>';
    }
}
