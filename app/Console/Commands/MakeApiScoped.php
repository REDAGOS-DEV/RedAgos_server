<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class MakeApiScoped extends Command
{
    /**
     * The signature of the console command.
     */
    protected $signature = 'make:api-scoped 
                            {name : The base name of the files (e.g. Taxi, Product)} 
                            {--force : Overwrite existing files}';

    protected $description = 'Create a secure, company-scoped API stack (Controller, Service, Repo, Resource)';

    protected Filesystem $files;

    /**
     * CONTROLLER STUB
     * Automatically passes $request->user() to the service.
     */
    protected const CONTROLLER_STUB = <<<'STUB'
    <?php

    namespace App\Http\Controllers;

    use App\Service\DummyService;
    use Illuminate\Http\Request;
    use Illuminate\Http\JsonResponse;

    class DummyController extends Controller
    {
        private DummyService $dummyService;

        public function __construct(DummyService $dummyService)
        {
            $this->dummyService = $dummyService;
        }

        public function index(Request $request)
        {
            return $this->dummyService->listDummy(
                $request->user(), 
                $request->input('per_page', 15)
            );
        }

        public function store(Request $request)
        {
            return $this->dummyService->createDummy($request->user(), $request->all());
        }

        public function show(Request $request, string $uuid)
        {
            return $this->dummyService->getDummy($request->user(), $uuid);
        }

        public function update(Request $request, string $uuid)
        {
            return $this->dummyService->updateDummy($request->user(), $uuid, $request->all());
        }

        public function destroy(Request $request, string $uuid)
        {
            $this->dummyService->deleteDummy($request->user(), $uuid);
            return response()->json(['message' => 'Deleted successfully'], 200);
        }
        
        public function restore(Request $request, string $uuid)
        {
            return $this->dummyService->restoreDummy($request->user(), $uuid);
        }
    }
    STUB;

    /**
     * SERVICE STUB
     * Contains the "Force Field" logic for company_id.
     */
    protected const SERVICE_STUB = <<<'STUB'
    <?php
    
    namespace App\Service;
    
    use App\Repository\DummyRepository;
    use App\Http\Resources\DummyResource;
    use App\Models\User;
    
    class DummyService
    {
        private DummyRepository $dummyRepository;
    
        public function __construct(DummyRepository $dummyRepository) 
        {
            $this->dummyRepository = $dummyRepository;
        }

        public function createDummy(User $actor, array $payload)
        {
            if (! $actor->hasRole('superadmin')) {
                $payload['company_id'] = $actor->company_id;
            }
    
            $model = $this->dummyRepository->create($payload);
            return new DummyResource($model);
        }
    
        public function listDummy(User $actor, int $perPage = 15)
        {
            $companyId = $actor->hasRole('superadmin') ? null : $actor->company_id;

            $collection = $this->dummyRepository->paginate($perPage, $companyId);
            return DummyResource::collection($collection);
        }
    
        /**
         * Helper to ensure the actor owns the record
         */
        private function findScoped(User $actor, string $uuid)
        {
            $model = $this->dummyRepository->findByUuid($uuid);
            
            if (! $model) {
                abort(404, 'Resource not found');
            }

            if (! $actor->hasRole('superadmin')) {
                if ($model->company_id !== $actor->company_id) {
                    abort(403, 'Unauthorized access to this resource.');
                }
            }
            return $model;
        }
    
        public function getDummy(User $actor, string $uuid)
        {
            $model = $this->findScoped($actor, $uuid);
            return new DummyResource($model);
        }
    
        public function updateDummy(User $actor, string $uuid, array $payload)
        {
            $this->findScoped($actor, $uuid);
            
            unset($payload['company_id']); 

            $model = $this->dummyRepository->update($uuid, $payload);
            return new DummyResource($model);
        }
    
        public function deleteDummy(User $actor, string $uuid)
        {
            $this->findScoped($actor, $uuid);
            $this->dummyRepository->delete($uuid);
            return true;
        }

        public function restoreDummy(User $actor, string $uuid)
        {
            $model = $this->dummyRepository->restore($uuid);

            if (! $actor->hasRole('superadmin') && $model->company_id !== $actor->company_id) {
                $model->delete(); 
                abort(403, 'Unauthorized');
            }
            
            return new DummyResource($model);
        }
    }
    STUB;

    /**
     * REPOSITORY STUB
     * Adds the $companyId filter to pagination.
     */
    protected const REPOSITORY_STUB = <<<'STUB'
    <?php
    
    namespace App\Repository;
    
    use App\Models\Dummy;
    use Illuminate\Database\Eloquent\ModelNotFoundException;
    
    class DummyRepository
    {
        public function paginate(int $perPage = 15, ?string $companyId = null)
        {
            $query = Dummy::latest();

            if ($companyId) {
                $query->where('company_id', $companyId);
            }

            return $query->paginate($perPage);
        }
    
        public function create(array $payload)
        {
            return Dummy::create($payload);
        }
    
        public function findByUuid(string $uuid)
        {
            return Dummy::where('uuid', $uuid)->first();
        }
    
        public function update(string $uuid, array $payload)
        {
            $model = $this->findByUuid($uuid);
            if ($model) {
                $model->update($payload);
            }
            return $model;
        }
    
        public function delete(string $uuid)
        {
            $model = $this->findByUuid($uuid);
            if ($model) {
                return $model->delete();
            }
            return false;
        }

        public function restore(string $uuid)
        {
            $model = Dummy::withTrashed()->where('uuid', $uuid)->firstOrFail();
            $model->restore();
            return $model;
        }
    }
    STUB;

    /**
     * RESOURCE STUB
     * (Standard JSON Resource)
     */
    protected const RESOURCE_STUB = <<<'STUB'
    <?php
    
    namespace App\Http\Resources;
    
    use Illuminate\Http\Request;
    use Illuminate\Http\Resources\Json\JsonResource;
    
    class DummyResource extends JsonResource
    {
        public function toArray(Request $request): array
        {
            return parent::toArray($request);
        }
    }
    STUB;

    public function __construct()
    {
        parent::__construct();
        $this->files = new Filesystem;
    }

    public function handle()
    {
        $baseName = Str::studly($this->argument('name'));

        if (! class_exists("App\\Models\\{$baseName}")) {
            if ($this->confirm("Model [{$baseName}] does not exist. Do you want to create it with migration?", true)) {
                $this->call('make:model', ['name' => $baseName, '-m' => true]);
            }
        }

        $this->info("Creating Scoped API layer for {$baseName}...");

        $this->createRepository($baseName);
        $this->createResource($baseName);
        $this->createService($baseName);
        $this->createController($baseName);

        $this->info("Scoped API layer created. Don't forget to add routes!");

        return 0;
    }

    protected function createController(string $baseName)
    {
        $className = "{$baseName}Controller";
        $serviceClass = "{$baseName}Service";
        $serviceVariable = lcfirst($serviceClass);
        $subPath = 'Http/Controllers';
        $targetDir = app_path($subPath);
        $targetFile = "{$targetDir}/{$className}.php";

        if (! $this->option('force') && $this->files->exists($targetFile)) {
            return;
        }
        $this->files->ensureDirectoryExists($targetDir);

        $stub = str_replace(
            ['DummyController', 'DummyService', 'dummyService', 'Dummy'],
            [$className, $serviceClass, $serviceVariable, $baseName],
            static::CONTROLLER_STUB
        );
        $this->files->put($targetFile, $stub);
        $this->info("Created controller: {$targetFile}");
    }

    protected function createService(string $baseName)
    {
        $className = "{$baseName}Service";
        $repoClass = "{$baseName}Repository";
        $resourceClass = "{$baseName}Resource";
        $repoVariable = lcfirst($repoClass);
        $subPath = 'Service';
        $targetDir = app_path($subPath);
        $targetFile = "{$targetDir}/{$className}.php";

        if (! $this->option('force') && $this->files->exists($targetFile)) {
            return;
        }
        $this->files->ensureDirectoryExists($targetDir);

        $stub = str_replace(
            ['DummyService', 'DummyRepository', 'DummyResource', 'dummyRepository', 'Dummy'],
            [$className, $repoClass, $resourceClass, $repoVariable, $baseName],
            static::SERVICE_STUB
        );
        $this->files->put($targetFile, $stub);
        $this->info("Created service: {$targetFile}");
    }

    protected function createRepository(string $baseName)
    {
        $className = "{$baseName}Repository";
        $subPath = 'Repository';
        $targetDir = app_path($subPath);
        $targetFile = "{$targetDir}/{$className}.php";

        if (! $this->option('force') && $this->files->exists($targetFile)) {
            return;
        }
        $this->files->ensureDirectoryExists($targetDir);

        $stub = str_replace(
            ['DummyRepository', 'Dummy'],
            [$className, $baseName],
            static::REPOSITORY_STUB
        );
        $this->files->put($targetFile, $stub);
        $this->info("Created repository: {$targetFile}");
    }

    protected function createResource(string $baseName)
    {
        $className = "{$baseName}Resource";
        $subPath = 'Http/Resources';
        $targetDir = app_path($subPath);
        $targetFile = "{$targetDir}/{$className}.php";

        if (! $this->option('force') && $this->files->exists($targetFile)) {
            return;
        }
        $this->files->ensureDirectoryExists($targetDir);

        $stub = str_replace(['DummyResource'], [$className], static::RESOURCE_STUB);
        $this->files->put($targetFile, $stub);
        $this->info("Created resource: {$targetFile}");
    }
}
