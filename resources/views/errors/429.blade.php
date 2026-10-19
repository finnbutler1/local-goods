@include('errors.layout', [
    'code' => 429,
    'heading' => 'Too many tries',
    'explanation' => 'To keep accounts safe, this was tried too often in a short time. Wait a minute, then try again.',
])
