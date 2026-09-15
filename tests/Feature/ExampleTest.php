<?php

test('home redirects guests to the login page', function () {
    $this->get('/')->assertRedirect('/login');
});
