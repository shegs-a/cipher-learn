<?php

declare(strict_types=1);

it('renders the branded placeholder page', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('CipherLearn')
        ->assertSee('Inter'); // typeface proof block is present
});
