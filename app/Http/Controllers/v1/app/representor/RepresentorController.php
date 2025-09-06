<?php

namespace App\Http\Controllers\v1\app\representor;


use App\Models\User;
use App\Models\Document;
use App\Models\Agreement;
use App\Models\Representer;
use App\Models\Organization;
use App\Models\RepresenterTran;
use App\Traits\FileHelperTrait;
use App\Traits\PathHelperTrait;
use App\Traits\UtilHelperTrait;
use App\Enums\Types\TaskTypeEnum;
use App\Models\AgreementDocument;
use App\Traits\Helper\HelperTrait;
use Illuminate\Support\Facades\DB;
use App\Models\PendingTaskDocument;
use Illuminate\Support\Facades\App;
use App\Http\Controllers\Controller;
use App\Enums\Languages\LanguageEnum;
use App\Repositories\Storage\StorageRepositoryInterface;
use App\Http\Requests\v1\representor\StoreRepresentorRequest;
use App\Http\Requests\v1\representor\UpdateRepresentorRequest;
use App\Repositories\PendingTask\PendingTaskRepositoryInterface;
use App\Repositories\Representative\RepresentativeRepositoryInterface;

class RepresentorController extends Controller
{
    use UtilHelperTrait, PathHelperTrait, FileHelperTrait;
    protected $representativeRepository;
    protected $pendingTaskRepository;
    protected $storageRepository;
    public function __construct(
        RepresentativeRepositoryInterface $representativeRepository,
        PendingTaskRepositoryInterface $pendingTaskRepository,
        StorageRepositoryInterface $storageRepository
    ) {
        $this->representativeRepository = $representativeRepository;
        $this->pendingTaskRepository = $pendingTaskRepository;
        $this->storageRepository = $storageRepository;
    }

    public function edit($id)
    {
        $representor = DB::table('representers as r')
            ->where('r.id', $id)
            ->join('representor_documents as rd', 'rd.representor_id', 'r.id')
            ->join('documents as d', 'd.id', 'rd.document_id')
            ->join('check_lists as cl', 'cl.id', 'd.check_list_id')
            ->joinSub(function ($query) {
                $query->from('representer_trans as rt')
                    ->select(
                        'representer_id',
                        DB::raw("MAX(CASE WHEN language_name = 'fa' THEN full_name END) as repre_name_farsi"),
                        DB::raw("MAX(CASE WHEN language_name = 'en' THEN full_name END) as repre_name_english"),
                        DB::raw("MAX(CASE WHEN language_name = 'ps' THEN full_name END) as repre_name_pashto")
                    )
                    ->groupBy('representer_id');
            }, 'rt', 'rt.representer_id', '=', 'r.id')
            ->select(
                'r.id',
                'r.is_active',
                'rt.repre_name_farsi',
                'rt.repre_name_english',
                'rt.repre_name_pashto',
                'd.id as document_id',
                'd.actual_name',
                'd.path',
                'd.type',
                'd.size',
                'd.check_list_id',
                'cl.id as check_list_id',
                'cl.acceptable_mimes',
                'cl.acceptable_extensions',
                'cl.file_size'
            )
            ->first();

        if (!$representor)
            return response()->json(
                null,
                200,
                [],
                JSON_UNESCAPED_UNICODE
            );


        return response()->json(
            [
                'id' => $representor->id,
                'is_active' => (bool) $representor->is_active,
                'repre_name_farsi' => $representor->repre_name_farsi,
                'repre_name_english' => $representor->repre_name_english,
                'repre_name_pashto' => $representor->repre_name_pashto,
                'letter_of_intro' => [
                    "path" => $representor->path,
                    "document_id" => $representor->document_id,
                    "size" => $representor->size,
                    "type" => $representor->type,
                    "name" => $representor->actual_name,
                    "checklist_id" => $representor->check_list_id,
                ],
                'checklist' => [
                    "id" => $representor->check_list_id,
                    "acceptable_mimes" => $representor->acceptable_mimes,
                    "acceptable_extensions" => $representor->acceptable_extensions,
                    "file_size" => $representor->file_size,
                ],
            ],
            200,
            [],
            JSON_UNESCAPED_UNICODE
        );
    }

    public function index($organization_id)
    {
        $locale = App::getLocale();
        $userModel = $this->getModelName(User::class);
        $organizationModel = $this->getModelName(Organization::class);

        $representor = DB::table('representers as r')
            ->where('r.organization_id', $organization_id)
            ->join('representer_trans as rt', function ($join) use ($locale) {
                $join->on('r.id', '=', 'rt.representer_id')
                    ->where('rt.language_name', $locale);
            })
            ->join('agreement_representers as ar', 'ar.representer_id', '=', 'r.id')
            ->join('agreements as a', function ($join) {
                $join->on('a.id', '=', 'ar.agreement_id');
            })
            ->leftJoin('users as u', function ($join) use ($userModel) {
                $join->on('r.userable_id', '=', 'u.id')
                    ->where('r.userable_type', $userModel);
            })
            ->leftJoin('organizations as n', function ($join) use ($organizationModel) {
                $join->on('r.userable_id', '=', 'n.id')
                    ->where('r.userable_type', $organizationModel);
            })
            ->select(
                'r.id',
                'r.is_active',
                'r.userable_id',
                'r.userable_type',
                'r.created_at',
                'rt.full_name',
                'u.username',
                'a.id as agreement_id',
                'a.agreement_no',
                'a.start_date',
                'a.end_date',
                "u.username as saved_by"
            )
            ->orderBy('r.is_active', 'desc')
            ->get();

        return response()->json(
            $representor,
            200,
            [],
            JSON_UNESCAPED_UNICODE
        );
    }
    public function store(StoreRepresentorRequest $request)
    {
        $request->validated();
        $organization_id = $request->organization_id;
        $authUser = $request->user();
        $agreement = null;
        $organization = DB::table('organizations as n')
            ->where('n.id', $organization_id)
            ->first();
        if (!$organization) {
            return response()->json([
                'message' => __('app_translation.organization_not_found'),
            ], 200, [], JSON_UNESCAPED_UNICODE);
        }
        $agreement = Agreement::where('organization_id', $organization_id)
            ->whereNull('end_date')
            ->first();

        if (!$agreement) {
            $agreement = Agreement::where('organization_id', $organization_id)
                ->orderByDesc('end_date')
                ->first();
            if (!$agreement) {
                return response()->json([
                    'message' => __('app_translation.agreement_not_exists')
                ], 409);
            }
        }
        // 2. Transaction
        DB::beginTransaction();
        // 3. Store document
        $task = $this->pendingTaskRepository->pendingTaskExist(
            $request->user(),
            TaskTypeEnum::organization_registeration->value,
            $organization->id
        );
        if (!$task) {
            return response()->json([
                'message' => __('app_translation.task_not_found')
            ], 404);
        }
        $representativeDocumentsId = [];
        $documents = $this->pendingTaskRepository->pendingTaskDocuments($task->id);
        $this->storageRepository->documentStore($agreement->id, $organization->id, $documents, function ($documentData) use (&$representativeDocumentsId) {
            $checklist_id = $documentData['check_list_id'];
            $document = Document::create([
                'actual_name' => $documentData['actual_name'],
                'size' => $documentData['size'],
                'path' => $documentData['path'],
                'type' => $documentData['type'],
                'check_list_id' => $checklist_id,
            ]);
            array_push($representativeDocumentsId, $document->id);
            AgreementDocument::create([
                'document_id' => $document->id,
                'agreement_id' => $documentData['agreement_id'],
            ]);
        });
        // 4. make others false
        Representer::where('is_active', true)
            ->where('organization_id', $organization_id)
            ->update(['is_active' => false]);
        // Representative with agreement
        $representer = $this->representativeRepository->storeRepresentative(
            $request,
            $organization->id,
            $agreement->id,
            $representativeDocumentsId,
            true,
            $authUser->id,
            $this->getModelName(get_class($authUser))
        );

        $this->pendingTaskRepository->destroyPendingTask(
            $request->user(),
            TaskTypeEnum::organization_registeration->value,
            $organization->id
        );
        DB::commit();
        $full_name = $request["repre_name_english"];
        $locale = App::getLocale();
        if ($locale == "fa") {
            $full_name = $request["repre_name_farsi"];
        } else if ($locale == "ps") {
            $full_name = $request["repre_name_pashto"];
        }
        return response()->json([
            "representor" => [
                "id" => $representer->id,
                "full_name" => $full_name,
                "is_active" => true,
                "saved_by" => $authUser->username,
                "userable_type" => $representer->userable_type,
                "userable_id" => $representer->userable_id,
                "agreement_no" => $agreement->agreement_no,
                "agreement_id" => $agreement->id,
                "start_date" => $agreement->start_date,
                "end_date" => $agreement->end_date,
                "created_at" => $representer->created_at,
            ],
            'message' => __('app_translation.success'),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
    public function update(UpdateRepresentorRequest $request)
    {
        $request->validated();
        $representer_id = $request->id;
        $organization_id = $request->organization_id;
        $authUser = $request->user();
        $organization = DB::table('organizations as n')
            ->where('n.id', $organization_id)
            ->first();
        if (!$organization) {
            return response()->json([
                'message' => __('app_translation.organization_not_found'),
            ], 200, [], JSON_UNESCAPED_UNICODE);
        }

        $agreement = null;
        $agreement = Agreement::where('organization_id', $organization_id)
            ->whereNull('end_date')
            ->first();

        if (!$agreement) {
            $agreement = Agreement::where('organization_id', $organization_id)
                ->orderByDesc('end_date')
                ->first();
            if (!$agreement) {
                return response()->json([
                    'message' => __('app_translation.agreement_not_exists')
                ], 409);
            }
        }

        // Validate status
        $representer = Representer::find($representer_id);
        if (!$representer) {
            return response()->json([
                'message' => __('app_translation.representor_not_found')
            ], 404);
        }
        // 1. Transaction
        DB::beginTransaction();
        // 2. Store document
        $task = $this->pendingTaskRepository->pendingTaskExist(
            $request->user(),
            TaskTypeEnum::organization_registeration->value,
            null
        );
        if ($task) {
            // 3. New document is added
            $documentsId = [];
            $documentsChecklists = [];
            $documents = $this->pendingTaskRepository->pendingTaskDocuments($task->id);

            $this->storageRepository->documentStore($agreement->id, $organization->id, $documents, function ($documentData) use (&$documentsId, &$documentsChecklists, &$representer) {
                $checklist_id = $documentData['check_list_id'];
                $document = Document::create([
                    'actual_name' => $documentData['actual_name'],
                    'size' => $documentData['size'],
                    'path' => $documentData['path'],
                    'type' => $documentData['type'],
                    'check_list_id' => $checklist_id,
                ]);
                array_push($documentsId, $document->id);
                array_push($documentsChecklists, $checklist_id);
                AgreementDocument::create([
                    'document_id' => $document->id,
                    'agreement_id' => $documentData['agreement_id'],
                ]);
            });
            // 3.1 Get and delete previous document
            $docsToDelete = DB::table('representor_documents as rd')
                ->where('rd.representor_id', $representer->id)
                ->join('documents as d', function ($join) use ($documentsChecklists, $documentsId) {
                    $join->on('d.id', '=', 'rd.document_id')
                        ->whereIn('d.check_list_id', $documentsChecklists)
                        ->whereNotIn('d.id', $documentsId);
                })
                ->select(
                    'd.id',
                    'd.path',
                )
                ->get();
            foreach ($docsToDelete as $document) {
                // Perform some action with each document, for example, deleting it
                $this->deleteDocument($this->transformToPrivate($document->path));
                DB::table('documents')->where('id', $document->id)->delete();
            }
        }
        if ($request->is_active && !$representer->is_active) {
            // 4. make others false
            Representer::where('is_active', true)
                ->where('organization_id', $organization_id)
                ->update(['is_active' => false]);
        }
        $representer->is_active = $request->is_active;
        $trans = RepresenterTran::where('representer_id', $representer->id)
            ->select('id', 'language_name', 'full_name')
            ->get();
        foreach (LanguageEnum::LANGUAGES as $code => $name) {
            $tran = $trans->where('language_name', $code)->first();
            if ($tran) {
                $tran->full_name = $request["repre_name_{$name}"];
                $tran->save();
            }
        }
        $representer->userable_type = $this->getModelName(get_class($authUser));
        $representer->userable_id = $authUser->id;
        $representer->save();
        DB::commit();
        $full_name = $request["repre_name_english"];
        $locale = App::getLocale();
        if ($locale == "fa") {
            $full_name = $request["repre_name_farsi"];
        } else if ($locale == "ps") {
            $full_name = $request["repre_name_pashto"];
        }
        return response()->json([
            "representor" => [
                "id" => $representer->id,
                "full_name" => $full_name,
                "agreement_no" => $agreement->agreement_no,
                "is_active" => $representer->is_active,
                "saved_by" => $authUser->username,
                "userable_type" => $representer->userable_type,
                "userable_id" => $representer->userable_id,
                "start_date" => $agreement->start_date,
                "end_date" => $agreement->end_date,
            ],
            'message' => __('app_translation.success'),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
    public function organizationRepresentorsName($id)
    {
        $locale = App::getLocale();
        // Joining necessary tables to fetch the organization data
        $representers = DB::table('representers as r')
            ->where('r.organization_id', $id)
            ->join('representer_trans as rt', function ($join) use ($locale) {
                $join->on('r.id', '=', 'rt.representer_id')
                    ->where('rt.language_name', $locale);
            })
            ->select(
                'r.id',
                "rt.full_name as name"
            )
            ->get();

        return response()->json($representers, 200, [], JSON_UNESCAPED_UNICODE);
    }
}
