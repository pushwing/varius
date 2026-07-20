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
            'uploadUrl' => site_url('takeout/upload'),
        ]);
    }
}
