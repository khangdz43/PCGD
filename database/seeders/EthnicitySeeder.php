<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EthnicitySeeder extends Seeder
{
    public function run(): void
    {
        $ethnicities = [
            ["id" => "01", "name" => "Kinh"],
            ["id" => "02", "name" => "Tày"],
            ["id" => "03", "name" => "Thái"],
            ["id" => "04", "name" => "Mường"],
            ["id" => "05", "name" => "Khơ Me"],
            ["id" => "06", "name" => "Hmong"],
            ["id" => "07", "name" => "Dao"],
            ["id" => "08", "name" => "Gia Rai"],
            ["id" => "09", "name" => "Ê Đê"],
            ["id" => "10", "name" => "Ba Na"],
            ["id" => "11", "name" => "Sán Chay"],
            ["id" => "12", "name" => "Chăm"],
            ["id" => "13", "name" => "Cơ Ho"],
            ["id" => "14", "name" => "Xơ Đăng"],
            ["id" => "15", "name" => "Sán Dìu"],
            ["id" => "16", "name" => "Hrê"],
            ["id" => "17", "name" => "Ra Glai"],
            ["id" => "18", "name" => "Mnông"],
            ["id" => "19", "name" => "Thổ"],
            ["id" => "20", "name" => "Stiêng"],
            ["id" => "21", "name" => "Khơ Mú"],
            ["id" => "22", "name" => "Bru - Vân Kiều"],
            ["id" => "23", "name" => "Cơ Tu"],
            ["id" => "24", "name" => "Giáy"],
            ["id" => "25", "name" => "Tà Ôi"],
            ["id" => "26", "name" => "Mạ"],
            ["id" => "27", "name" => "Co"],
            ["id" => "28", "name" => "Chơ Ro"],
            ["id" => "29", "name" => "Xinh Mun"],
            ["id" => "30", "name" => "Hà Nhì"],
            ["id" => "31", "name" => "Chu Ru"],
            ["id" => "32", "name" => "Lào"],
            ["id" => "33", "name" => "Kháng"],
            ["id" => "34", "name" => "La Chí"],
            ["id" => "35", "name" => "Phù Lá"],
            ["id" => "36", "name" => "La Ha"],
            ["id" => "37", "name" => "Pà Thẻn"],
            ["id" => "38", "name" => "Lự"],
            ["id" => "39", "name" => "Ngái"],
            ["id" => "40", "name" => "Chứt"],
            ["id" => "41", "name" => "Lô Lô"],
            ["id" => "42", "name" => "Mảng"],
            ["id" => "43", "name" => "Cơ Lao"],
            ["id" => "44", "name" => "Bố Y"],
            ["id" => "45", "name" => "Cống"],
            ["id" => "46", "name" => "Si La"],
            ["id" => "47", "name" => "Pu Péo"],
            ["id" => "48", "name" => "Rơ Măm"],
            ["id" => "49", "name" => "Brâu"],
            ["id" => "50", "name" => "Ơ Đu"],
            ["id" => "51", "name" => "Người Nước Ngoài"],
            ["id" => "52", "name" => "Hoa"],
            ["id" => "53", "name" => "Nùng"],
            ["id" => "54", "name" => "Gơ Rai"],
        ];

        $now = now();
        $data = [];

        foreach ($ethnicities as $item) {
            $data[] = [
                'code'       => 'DT' . $item['id'],
                'name'       => $item['name'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('ethnicities')->upsert(
            $data,
            ['code'],
            ['name', 'updated_at']
        );

        $this->command->info('Đã seed thành công ' . count($data) . ' dân tộc.');
    }
}
