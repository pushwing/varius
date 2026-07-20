<?php

declare(strict_types=1);

namespace App\Controllers;

class Home extends BaseController
{
    public function index(): string
    {
        helper('url');

        return view('home', [
            'userId' => $this->currentUserId(),
            'loginUrl' => site_url('auth/google'),
            'logoutUrl' => site_url('auth/logout'),
            'mapUrl' => site_url('map'),
            'sessionsUrl' => site_url('picker/sessions'),
            'statusUrl' => site_url('picker/sessions/status'),
            'ingestUrl' => site_url('picker/ingest'),
        ]);
    }
}
