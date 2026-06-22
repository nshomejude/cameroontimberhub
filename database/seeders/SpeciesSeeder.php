<?php

namespace Database\Seeders;

use App\Models\Species;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SpeciesSeeder extends Seeder
{
    public function run(): void
    {
        $species = [
            ['common_name' => 'Sapele', 'scientific_name' => 'Entandrophragma cylindricum', 'description' => 'A reddish-brown West African hardwood prized for furniture, joinery and decorative veneers, with an interlocked grain and ribbon figure.'],
            ['common_name' => 'Iroko', 'scientific_name' => 'Milicia excelsa', 'description' => 'A durable golden-brown hardwood often used as a teak substitute for outdoor joinery, decking, marine work and worktops.'],
            ['common_name' => 'Ayous', 'scientific_name' => 'Triplochiton scleroxylon', 'description' => 'Also called Obeche, a pale, lightweight and stable hardwood widely exported for joinery, mouldings, plywood and core stock.'],
            ['common_name' => 'Tali', 'scientific_name' => 'Erythrophleum ivorense', 'description' => 'A very dense, durable reddish hardwood used for heavy construction, hydraulic works, decking and railway sleepers.'],
            ['common_name' => 'Padouk', 'scientific_name' => 'Pterocarpus soyauxii', 'description' => 'A vivid red-orange hardwood with excellent durability, used for flooring, joinery, turning and decorative work.'],
            ['common_name' => 'Azobe', 'scientific_name' => 'Lophira alata', 'description' => 'An extremely hard and heavy hardwood for marine, hydraulic and heavy civil construction, including docks and bridges.'],
            ['common_name' => 'Movingui', 'scientific_name' => 'Distemonanthus benthamianus', 'description' => 'A lustrous yellow-brown hardwood used for joinery, flooring, furniture and decorative veneers.'],
            ['common_name' => 'Moabi', 'scientific_name' => 'Baillonella toxisperma', 'description' => 'A fine, durable reddish hardwood valued for high-quality joinery, flooring, furniture and sliced veneer.'],
            ['common_name' => 'Okan', 'scientific_name' => 'Cylicodiscus gabunensis', 'description' => 'A dense and very durable hardwood used in heavy construction, hydraulic works, decking and exterior applications.'],
            ['common_name' => 'Bubinga', 'scientific_name' => 'Guibourtia tessmannii', 'description' => 'A heavy, lustrous reddish hardwood with striking figure used for fine furniture, veneers and instruments. CITES-listed.', 'is_cites_listed' => true, 'cites_appendix' => 'II'],
        ];

        foreach ($species as $i => $row) {
            Species::firstOrCreate(
                ['slug' => Str::slug($row['common_name'])],
                array_merge([
                    'is_cites_listed' => false,
                    'cites_appendix' => null,
                    'is_published' => true,
                    'sort_order' => $i,
                ], $row),
            );
        }
    }
}
