<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER persons_one_head_before_insert
            BEFORE INSERT ON persons
            FOR EACH ROW
            BEGIN
                IF NEW.relationship_with_head = 'Chủ hộ'
                   AND EXISTS (
                       SELECT 1 FROM persons
                       WHERE household_id = NEW.household_id
                         AND relationship_with_head = 'Chủ hộ'
                   ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Mỗi phiếu chỉ được có một chủ hộ';
                END IF;
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER persons_one_head_before_update
            BEFORE UPDATE ON persons
            FOR EACH ROW
            BEGIN
                IF NEW.relationship_with_head = 'Chủ hộ'
                   AND (
                       NOT (OLD.relationship_with_head <=> 'Chủ hộ')
                       OR OLD.household_id <> NEW.household_id
                   )
                   AND EXISTS (
                       SELECT 1 FROM persons
                       WHERE household_id = NEW.household_id
                         AND relationship_with_head = 'Chủ hộ'
                         AND id <> OLD.id
                   ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Mỗi phiếu chỉ được có một chủ hộ';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS persons_one_head_before_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS persons_one_head_before_update');
    }
};
