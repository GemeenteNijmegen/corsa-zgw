<?php

it('redirects the home page to the GitHub repository by default', function (): void {
    $this->get('/')
        ->assertRedirect('https://github.com/GemeenteNijmegen/corsa-zgw');
});

it('redirects the home page to the configured url', function (): void {
    config(['app.home_redirect_url' => 'https://example.org']);

    $this->get('/')
        ->assertRedirect('https://example.org');
});
