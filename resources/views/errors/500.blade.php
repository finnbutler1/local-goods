@include('errors.layout', [
    'code' => 500,
    'heading' => 'Something went wrong on our side',
    'explanation' => "It wasn't anything you did, and it has been logged. If you were paying, giving credits or redeeming, check the page before trying again so it doesn't happen twice.",
])
