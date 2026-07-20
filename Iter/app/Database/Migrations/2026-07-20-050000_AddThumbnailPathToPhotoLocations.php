<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddThumbnailPathToPhotoLocations extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('photo_locations', [
            'thumbnail_path' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'lng'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('photo_locations', 'thumbnail_path');
    }
}
