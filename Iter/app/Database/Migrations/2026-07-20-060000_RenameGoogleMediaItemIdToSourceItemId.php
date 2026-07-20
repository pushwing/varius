<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class RenameGoogleMediaItemIdToSourceItemId extends Migration
{
    public function up(): void
    {
        $this->forge->modifyColumn('photo_locations', [
            'google_media_item_id' => [
                'name' => 'source_item_id',
                'type' => 'VARCHAR',
                'constraint' => 255,
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->modifyColumn('photo_locations', [
            'source_item_id' => [
                'name' => 'google_media_item_id',
                'type' => 'VARCHAR',
                'constraint' => 255,
            ],
        ]);
    }
}
