<?php

namespace App\Controllers;

use CodeIgniter\Controller;

final class AdminController extends Controller
{
    public function index(): string
    {
        return view('admin/index');
    }
}
