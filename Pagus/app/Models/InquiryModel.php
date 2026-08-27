<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

final class InquiryModel extends Model
{
    protected $table = 'inquiries';
    protected $returnType = 'array';
    protected $allowedFields = ['name', 'contact', 'message', 'status'];
    protected $useTimestamps = true;
}
