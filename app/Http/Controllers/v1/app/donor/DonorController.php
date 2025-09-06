<?php

namespace App\Http\Controllers\v1\app\donor;

use App\Models\Donor;

use App\Models\Email;
use App\Models\Address;
use App\Models\Contact;
use App\Models\DonorTran;
use App\Models\AddressTran;
use App\Models\DonorStatus;
use App\Traits\FilterTrait;
use Illuminate\Http\Request;
use App\Traits\CacheKeyTrait;
use App\Enums\Statuses\StatusEnum;
use Illuminate\Support\Facades\DB;
use App\Enums\Permissions\RoleEnum;
use Illuminate\Support\Facades\App;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use App\Enums\Languages\LanguageEnum;
use Illuminate\Support\Facades\Cache;
use App\Http\Requests\v1\donor\DonorUpdateRequest;
use App\Http\Requests\v1\donor\DonorRegisterRequest;

class DonorController extends Controller
{
    use FilterTrait, CacheKeyTrait;
    private $statisticCacheName = "donor_statistic";

    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 10); // Number of records per page
        $page = $request->input('page', 1); // Current page
        $locale = App::getLocale();

        $query = DB::table('donors as don')
            ->join('donor_trans as dont', function ($join) use ($locale) {
                $join->on('don.id', '=', 'dont.donor_id')
                    ->where('dont.language_name', $locale);
            })
            ->join('emails as e', 'e.id', '=', 'don.email_id')
            ->join('contacts as c', 'c.id', '=', 'don.contact_id')
            ->select(
                'don.id',
                'don.profile',
                'don.abbr',
                'don.username',
                'dont.name as name',
                'e.value as email',
                'c.value as contact',
                'don.created_at'
            );

        $this->applyDate($query, $request, 'don.created_at', 'don.created_at');
        $allowColumn = [
            'name' => 'dont.name',
            'username' => 'don.username',
            'abbr' => 'don.abbr'
        ];
        $this->applyFilters($query, $request, $allowColumn);
        $this->applySearch($query, $request, $allowColumn);

        $result = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'donor' => $result
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function nameWithId(Request $request)
    {
        $locale = App::getLocale();

        $result = DB::table('donors as don')
            ->join('donor_trans as dont', function ($join) use ($locale) {
                $join->on('don.id', '=', 'dont.donor_id')
                    ->where('dont.language_name', $locale);
            })
            ->select(
                'don.id',
                'don.username as name',
            )->get();

        return response()->json(
            $result,
            200,
            [],
            JSON_UNESCAPED_UNICODE
        );
    }
    //
    public function list()
    {
        $locale = App::getLocale();

        $query = DB::table('donor_trans as dont')
            ->where('dont.language_name', $locale)
            ->select(
                'dont.donor_id as id',
                'dont.name as name'
            )->get();


        return response()->json(
            $query,
            200,
            [],
            JSON_UNESCAPED_UNICODE
        );
    }
    public function store(DonorRegisterRequest $request)
    {
        $validatedData = $request->validated();
        $locale = App::getLocale();

        // Create email
        $email = Email::where('value', '=', $validatedData['email'])->first();
        if ($email) {
            return response()->json([
                'message' => __('app_translation.email_exist'),
            ], 400, [], JSON_UNESCAPED_UNICODE);
        }
        $contact = Contact::where('value', '=', $validatedData['contact'])->first();
        if ($contact) {
            return response()->json([
                'message' => __('app_translation.contact_exist'),
            ], 400, [], JSON_UNESCAPED_UNICODE);
        }
        // Begin transaction
        DB::beginTransaction();
        $email = Email::create(['value' => $validatedData['email']]);
        $contact = Contact::create(['value' => $validatedData['contact']]);
        // Create address
        $address = Address::create([
            'district_id' => $validatedData['district_id'],
            'province_id' => $validatedData['province_id'],
        ]);

        // * Translations
        foreach (LanguageEnum::LANGUAGES as $code => $name) {
            AddressTran::create([
                'address_id' => $address->id,
                'area' => $validatedData["area_{$name}"],
                'language_name' =>  $code,
            ]);
        }
        // Create donor
        $newDonor = Donor::create([
            'abbr' => $validatedData['abbr'],
            'role_id' => RoleEnum::donor->value,
            'address_id' => $address->id,
            'email_id' => $email->id,
            'username' => $request->username,
            'contact_id' => $contact->id,
            'profile' => null,
            "password" => Hash::make($validatedData['password']),
        ]);


        foreach (LanguageEnum::LANGUAGES as $code => $name) {
            DonorTran::create([
                'donor_id' => $newDonor->id,
                'language_name' => $code,
                'name' => $validatedData["name_{$name}"],
            ]);
        }
        DonorStatus::create([
            'donor_id' => $newDonor->id,
            'is_active' => true,
            'status_id' => StatusEnum::active->value,
            'comment' =>   'Register the Donor',
            'user_id' => $request->user()->id,
        ]);

        $name = $validatedData['name_english'];


        if ($locale == 'fa') {
            $name = $validatedData['name_english'];
        }
        if ($locale === 'ps') {
            $name = $validatedData['name_pashto'];
        }

        DB::commit();
        Cache::forget($this->getKey($this->statisticCacheName));

        return response()->json(
            [
                'message' => __('app_translation.success'),
                "donor" => [
                    "id" => $newDonor->id,
                    "profile" => $newDonor->profile,
                    "abbr" => $newDonor->abbr,
                    "username" => $validatedData['username'],
                    "name" => $name,
                    "contact" => $validatedData['contact'],
                    "email" => $validatedData['email'],
                    "created_at" => $newDonor->created_at,

                ]
            ],
            200,
            [],
            JSON_UNESCAPED_UNICODE
        );
    }

    public function edit($id)
    {
        $locale = App::getLocale();
        // 1. Get donor information

        $donor = DB::table('donors as don')->where('don.id', $id)
            ->join('donor_trans as dont', 'dont.donor_id', '=', 'don.id')
            ->join('emails as e', 'e.id', '=', 'don.email_id')
            ->join('contacts as c', 'c.id', '=', 'don.contact_id')
            ->join('addresses as a', 'a.id', '=', 'don.address_id')
            ->join('address_trans as at', 'at.address_id', '=', 'a.id')
            ->join('district_trans as dt', function ($join) use ($locale) {
                $join->on('dt.district_id', '=', 'a.district_id')
                    ->where('dt.language_name', $locale);
            })
            ->join('province_trans as pt', function ($join) use ($locale) {
                $join->on('pt.province_id', '=', 'a.province_id')
                    ->where('pt.language_name', $locale);
            })
            ->select(
                'don.id',
                'don.profile',
                'don.username',
                'don.abbr',
                'c.value as contact',
                'e.value as email',
                'dt.value as district',
                'dt.district_id',
                'pt.value as province',
                'pt.province_id',
                // Aggregating the name by conditional filtering for each language
                DB::raw("MAX(CASE WHEN dont.language_name = 'ps' THEN dont.name END) as name_pashto"),
                DB::raw("MAX(CASE WHEN dont.language_name = 'fa' THEN dont.name END) as name_farsi"),
                DB::raw("MAX(CASE WHEN dont.language_name = 'en' THEN dont.name END) as name_english"),
                DB::raw("MAX(CASE WHEN at.language_name = 'ps' THEN at.area END) as area_pashto"),
                DB::raw("MAX(CASE WHEN at.language_name = 'fa' THEN at.area END) as area_farsi"),
                DB::raw("MAX(CASE WHEN at.language_name = 'en' THEN at.area END) as area_english")
            )
            ->groupBy(
                'don.id',
                'don.profile',
                'don.username',
                'don.abbr',
                'c.value',
                'e.value',
                'dt.value',
                'dt.district_id',
                'pt.value',
                'pt.province_id',
            )
            ->first();

        if (!$donor) {
            return response()->json([
                'message' => __('app_translation.donor_not_found'),
            ], 404, [], JSON_UNESCAPED_UNICODE);
        }

        $result = [
            "profile" => $donor->profile,
            "username" => $donor->username,
            "contact" => $donor->contact,
            "email" => $donor->email,
            "id" => $donor->id,
            "abbr" => $donor->abbr,
            "name_english" => $donor->name_english,
            "name_farsi" => $donor->name_farsi,
            "name_pashto" => $donor->name_pashto,
            "contact" => $donor->contact,
            "email" => $donor->email,
            "province" => ["id" => $donor->province_id, "name" => $donor->province],
            "district" => ["id" => $donor->district_id, "name" => $donor->district],
            "area_english" => $donor->area_english,
            "area_pashto" => $donor->area_pashto,
            "area_farsi" => $donor->area_farsi,
        ];
        return response()->json([
            'donor' => $result,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function update(DonorUpdateRequest $request)
    {
        $id = $request->id;
        // 1. Get donor
        $donor = Donor::find($id);
        if (!$donor) {
            return response()->json([
                'message' => __('app_translation.donor_not_found'),
            ], 404, [], JSON_UNESCAPED_UNICODE);
        }
        // Begin transaction
        DB::beginTransaction();
        $email = Email::where('value', $request->email)
            ->select('id')->first();
        // Email Is taken by someone
        if ($email) {
            if ($email->id == $donor->email_id) {
                $email->value = $request->email;
                $email->save();
            } else {
                return response()->json([
                    'message' => __('app_translation.email_exist'),
                ], 409, [], JSON_UNESCAPED_UNICODE);
            }
        } else {
            $email = Email::where('id', $donor->email_id)->first();
            $email->value = $request->email;
            $email->save();
        }
        $contact = Contact::where('value', $request->contact)
            ->select('id')->first();
        if ($contact) {
            if ($contact->id == $donor->contact_id) {
                $contact->value = $request->contact;
                $contact->save();
            } else {
                return response()->json([
                    'message' => __('app_translation.contact_exist'),
                ], 409, [], JSON_UNESCAPED_UNICODE);
            }
        } else {
            $contact = Contact::where('id', $donor->contact_id)->first();
            $contact->value = $request->contact;
            $contact->save();
        }


        $address = Address::where('id', $donor->address_id)
            ->select("district_id", "id", "province_id")
            ->first();
        if (!$address) {
            return response()->json([
                'message' => __('app_translation.address_not_found'),
            ], 404, [], JSON_UNESCAPED_UNICODE);
        }

        // 4. Update donor information
        $donor->abbr = $request->abbr;
        $donor->username = $request->username;

        $address->province_id = $request->province['id'];
        $address->district_id = $request->district['id'];

        // * Translations
        $addressTrans = AddressTran::where('address_id', $address->id)->get();
        $donorTrans = DonorTran::where('donor_id', $donor->id)->get();
        foreach (LanguageEnum::LANGUAGES as $code => $name) {
            $addressTran = $addressTrans->where('language_name', $code)->first();
            $donorTran = $donorTrans->where('language_name', $code)->first();
            $addressTran->update([
                'area' => $request["area_{$name}"],
            ]);
            $donorTran->update([
                'name' => $request["name_{$name}"],
            ]);
        }

        // 5. Completed
        $donor->save();
        $address->save();

        DB::commit();
        Cache::forget($this->getKey($this->statisticCacheName));

        return response()->json([
            'message' => __('app_translation.success'),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            "confirm_password" => ["required", "min:8", "max:45"],
            "new_password" => ["required", "min:8", "max:45"],
        ]);
        $donor = Donor::where('id', $request->donor_id)->first();
        if (!$donor) {
            return response()->json([
                'message' => __('app_translation.donor_not_found'),
            ], 404, [], JSON_UNESCAPED_UNICODE);
        }
        DB::beginTransaction();

        $donor->password = Hash::make($request->new_password);
        $donor->save();
        DB::commit();
        return response()->json([
            'message' => __('app_translation.success'),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function statistics()
    {
        $registered = StatusEnum::active->value;
        $block = StatusEnum::block->value;

        $statistics = Cache::remember($this->getKey($this->statisticCacheName), 180, function () use ($registered, $block) {
            return DB::select("
            SELECT
                COUNT(n.id) AS \"donorCount\",
                (SELECT COUNT(*) FROM donors WHERE DATE(created_at) = CURRENT_DATE) AS \"todayCount\",
                (
                    SELECT COUNT(*)
                    FROM donors n2
                    INNER JOIN donor_statuses ns ON ns.donor_id = n2.id
                    WHERE ns.status_id = ?
                ) AS \"activeCount\",
                (
                    SELECT COUNT(*)
                    FROM donors n3
                    INNER JOIN donor_statuses ns ON ns.donor_id = n3.id
                    WHERE ns.status_id = ?
                ) AS \"inActiveCount\"
            FROM donors n
        ", [$registered, $block]);
        });

        return response()->json([
            'counts' => [
                "count" => $statistics[0]->donorCount,
                "todayCount" => $statistics[0]->todayCount,
                "activeCount" => $statistics[0]->activeCount,
                "unRegisteredCount" => $statistics[0]->inActiveCount,
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
