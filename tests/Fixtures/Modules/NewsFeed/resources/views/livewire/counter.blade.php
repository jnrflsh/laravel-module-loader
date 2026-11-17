<?php

use Livewire\Component;

new class extends Component
{
    public int $count = 1;
};
?>

<div>count: {{ $count }}</div>
