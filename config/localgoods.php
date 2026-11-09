<?php

return [
    // Where every "Request a demo" button sends its email. Built in one place
    // by App\Support\DemoRequestLink. Nothing is sent from the server.
    'demo_email' => env('LOCALGOODS_DEMO_EMAIL', 'butlerf@cua.edu'),
];
