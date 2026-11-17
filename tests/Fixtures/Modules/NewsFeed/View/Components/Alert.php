<?php

namespace Jnrflsh\ModuleLoader\Tests\Fixtures\Modules\NewsFeed\View\Components;

use Illuminate\View\Component;

class Alert extends Component
{
    public function render(): string
    {
        return '<div class="alert">{{ $slot }}</div>';
    }
}
