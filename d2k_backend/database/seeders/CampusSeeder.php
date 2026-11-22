<?php

namespace Database\Seeders;

use App\Models\Campus;
use Illuminate\Database\Seeder;

/**
 * Starter list of Dar es Salaam universities and colleges for campus delivery.
 *
 * Idempotent and non-destructive: a campus is matched on its name and only
 * inserted when missing. A campus that already exists is left exactly as an
 * administrator last saved it (fee, active flag, order, coordinates), so
 * re-running this never undoes admin edits.
 *
 * Coordinates are deliberately left empty: a wrong pin would misprice
 * distance-based delivery, so they are for an administrator to fill in from a
 * map. Areas marked null were not certain enough to state.
 */
class CampusSeeder extends Seeder
{
    public function run(): void
    {
        // name, short name, area
        $campuses = [
            ['University of Dar es Salaam (UDSM) – Mlimani', 'UDSM Mlimani', 'Ubungo'],
            ["Dar es Salaam University College of Education (DUCE) – Chang'ombe", 'DUCE', "Chang'ombe, Temeke"],
            ['Ardhi University (ARU)', 'Ardhi', 'Ubungo'],
            ['Muhimbili University of Health and Allied Sciences (MUHAS)', 'MUHAS', 'Upanga, Ilala'],
            ['Institute of Finance Management (IFM)', 'IFM', 'Kivukoni, Ilala'],
            ['Dar es Salaam Institute of Technology (DIT)', 'DIT', 'Ilala'],
            ['College of Business Education (CBE) – Dar es Salaam', 'CBE Dar', 'Ilala'],
            ['National Institute of Transport (NIT)', 'NIT', 'Mabibo, Ubungo'],
            ['Tanzania Institute of Accountancy (TIA) – Dar es Salaam', 'TIA Dar', null],
            ['Kampala International University in Tanzania (KIUT) – Dar es Salaam', 'KIUT', 'Gongo la Mboto, Ilala'],
            ['The Open University of Tanzania (OUT) – Kinondoni', 'OUT Kinondoni', 'Kinondoni'],
            ['Hubert Kairuki Memorial University (HKMU)', 'HKMU', 'Mikocheni, Kinondoni'],
            ['International Medical & Technological University (IMTU)', 'IMTU', 'Mbezi Beach, Kinondoni'],
            ['Mwalimu Nyerere Memorial Academy (MNMA) – Kivukoni', 'MNMA Kivukoni', 'Kigamboni'],
            ['St. Joseph University in Tanzania (SJUIT)', 'SJUIT', 'Mbezi, Ubungo'],
            ['Tumaini University Dar es Salaam College (TUDARCo)', 'TUDARCo', 'Mikocheni, Kinondoni'],
            ['Eastern Africa Statistical Training Centre (EASTC)', 'EASTC', 'Changanyikeni, Ubungo'],
            ['Institute of Social Work (ISW)', 'ISW', 'Kijitonyama, Kinondoni'],
            ['Water Institute – Ubungo', 'Water Institute', 'Ubungo'],
            ['Bandari College', 'Bandari College', null],
            ['Dar es Salaam Maritime Institute (DMI)', 'DMI', 'Ilala'],
            ['Mzumbe University – Dar es Salaam Campus College', 'Mzumbe Dar', null],
            ['Institute of Adult Education (IAE)', 'IAE', null],
        ];

        foreach ($campuses as $index => [$name, $shortName, $area]) {
            Campus::firstOrCreate(
                ['name' => $name],
                [
                    'short_name' => $shortName,
                    'area'       => $area,
                    'is_active'  => true,
                    'sort_order' => ($index + 1) * 10,
                ],
            );
        }
    }
}
