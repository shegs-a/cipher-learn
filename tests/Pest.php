<?php

use Tests\TestCase;

/*
 * Feature tests get the full framework TestCase. Vite is disabled by default so
 * Blade views that reference @vite don't require a built manifest to render in
 * CI — asset building is a separate concern from behaviour.
 */
pest()->extend(TestCase::class)
    ->beforeEach(function () {
        $this->withoutVite();
    })
    ->in('Feature');
