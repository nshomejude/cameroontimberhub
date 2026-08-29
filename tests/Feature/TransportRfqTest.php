<?php

use App\Enums\RfqType;

test('the transport entry point tags the wizard session with RfqType::Transport', function () {
    $response = $this->get('/request-quote/transport');

    $response->assertOk();
    expect(session('rfq_wizard')['type'])->toBe(RfqType::Transport->value);
});

test('the transport entry point renders the same wizard view as export', function () {
    $response = $this->get('/request-quote/transport');

    $response->assertViewIs('public.rfq.wizard');
});
