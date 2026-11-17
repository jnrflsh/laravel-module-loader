<?php

namespace Jnrflsh\ModuleLoader\Tests\Fixtures\Modules\NewsFeed\Livewire;

/**
 * Loading this class fails: discovery must never load a module's classes.
 */
class Unloadable extends MissingParentComponent {}
