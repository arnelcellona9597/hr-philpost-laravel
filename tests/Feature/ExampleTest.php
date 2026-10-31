<?php

it('sends visitors to the sign-in page', function () {
    $this->get('/')->assertRedirect('/login');
});
