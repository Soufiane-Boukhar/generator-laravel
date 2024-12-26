<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class SoufianeGenerator extends Command
{
    protected $signature = 'generate:full-code {name}';
    protected $description = 'Generate a full set of files (repository, controller, model, migration, and AdminLTE views) for a given entity';
    protected $relationships = [];
    protected $foreignKeys = [];

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $name = $this->argument('name');
        $this->info("Generating full code for: $name");

        [$fillable, $attributeTypes] = $this->askForFillableAttributes(); 
        $migrationColumns = $this->askForMigrationColumns();
        
        if ($this->confirm("Do you want to add relationships to the model?")) {
            $this->askForRelationships($name);
        }

        $this->generateBaseRepository();
        $this->generateModel($name, $fillable);
        $this->info("Model created for: $name");

        $this->generateMigration($name, $migrationColumns);
        $this->info("Migration created for: $name");

        $this->generateController($name, $fillable);
        $this->info("Controller created: {$name}Controller");

        $this->generateRepository($name);
        $this->generateLayout($name);
        $this->generateRoutes($name);
        $this->generateViews($name, $fillable, $attributeTypes);

        $this->info("Full code generation completed for: $name");
        return 0;
    }

    private function askForFillableAttributes()
{
    $fillable = [];
    $attributeTypes = []; 

    $this->info("Enter the fillable attributes for the model (comma-separated):");
    $fillableInput = $this->ask("Example: name,email,password");

    if ($fillableInput) {
        $attributes = array_map('trim', explode(',', $fillableInput));

        foreach ($attributes as $attribute) {
            $type = $this->choice(
                "Select the type for the '$attribute' attribute:",
                ['text', 'number','select', 'textarea', 'password', 'tel'],
                0  
            );

            $fillable[] = $attribute; 
            $attributeTypes[$attribute] = $type;
        }
    }

    return [$fillable, $attributeTypes]; 
}



    private function askForMigrationColumns()
    {
        $columns = [];
        $addColumn = true;

        while ($addColumn) {
            $name = $this->ask("For migration enter the column name:");
            $type = $this->choice("Select the column type", [
                'string',
                'integer',
                'bigInteger',
                'boolean',
                'text',
                'date',
                'datetime',
                'float',
                'double',
                'decimal',
                'json',
                'jsonb'
            ], 0);
            $nullable = $this->confirm("Should this column be nullable?");

            $columns[] = compact('name', 'type', 'nullable');

            $addColumn = $this->confirm("Do you want to add another column?");

            if (!$addColumn) {
                if ($this->confirm("Do you want this column to be a foreign key?")) {
                    $referenceTable = $this->ask("Enter the reference table for the foreign key:");
                    $referenceColumn = $this->ask("Enter the reference column for the foreign key (default: id):", 'id');
                    $columns[count($columns) - 1]['isForeignKey'] = true;
                    $columns[count($columns) - 1]['referenceTable'] = $referenceTable;
                    $columns[count($columns) - 1]['referenceColumn'] = $referenceColumn;

                    $this->foreignKeys[] = [
                        'column' => $name,
                        'referenceTable' => $referenceTable,
                        'referenceColumn' => $referenceColumn
                    ];
                }
            }
        }

        return $columns;
    }

    private function askForRelationships($modelName)
    {
        $addRelation = true;
        
        while ($addRelation) {
            $relationType = $this->choice(
                "Select the type of relationship",
                ['hasOne', 'hasMany', 'belongsTo', 'belongsToMany', 'hasOneThrough', 'hasManyThrough', 'morphOne', 'morphMany', 'morphTo']
            );
            
            $relatedModel = $this->ask("Enter the name of the related model");
            $relatedModel = ucfirst($relatedModel);
            
            $details = [];
            
            if ($relationType === 'belongsTo') {
                $foreignKey = $this->ask("Enter the foreign key name (leave blank for default)") ?: 
                    Str::snake($relatedModel) . '_id';
                $details['foreign_key'] = $foreignKey;
            }
            
            if (in_array($relationType, ['belongsToMany', 'hasOneThrough', 'hasManyThrough'])) {
                if ($relationType === 'belongsToMany') {
                    $details['pivot_table'] = $this->ask("Enter the pivot table name (leave blank for default)") ?: 
                        Str::snake(min($modelName, $relatedModel)) . '_' . Str::snake(max($modelName, $relatedModel));
                        
                    $details['foreign_pivot_key'] = $this->ask("Enter the foreign pivot key (leave blank for default)") ?: 
                        Str::snake($modelName) . '_id';
                        
                    $details['related_pivot_key'] = $this->ask("Enter the related pivot key (leave blank for default)") ?: 
                        Str::snake($relatedModel) . '_id';
                } else {
                    $details['through_model'] = $this->ask("Enter the intermediate model name");
                    $details['first_key'] = $this->ask("Enter the first key (leave blank for default)");
                    $details['second_key'] = $this->ask("Enter the second key (leave blank for default)");
                }
            }
            
            if (in_array($relationType, ['morphOne', 'morphMany', 'morphTo'])) {
                $details['morphable_type'] = $this->ask("Enter the name for the morphable type column (leave blank for default)") ?: 
                    Str::snake($modelName) . '_type';
                $details['morphable_id'] = $this->ask("Enter the name for the morphable ID column (leave blank for default)") ?: 
                    Str::snake($modelName) . '_id';
            }
            
            $this->relationships[] = [
                'type' => $relationType,
                'model' => $relatedModel,
                'details' => $details
            ];
            
            $addRelation = $this->confirm("Do you want to add another relationship?");
        }
    }

    private function generateModel($name, $fillable)
    {
        $modelPath = app_path("Models/$name.php");
    
        $fillable = array_filter($fillable, 'is_string');
    
        $fillableContent = "protected \$fillable = [" . implode(", ", array_map(fn($attr) => "'$attr'", $fillable)) . "];";
    
        $relationshipMethods = "";
        $relationshipsUseStatements = "";
        foreach ($this->relationships as $relation) {
           
            
            $methodName = $this->getRelationshipMethodName($relation);
            $relationshipMethods .= $this->generateRelationshipMethod($relation, $methodName);
            $modelClass = $relation['model'];
            $relationshipsUseStatements .= "use App\Models\\$modelClass;\n";
        }
    
        $content = "<?php
    
    namespace App\Models;
    
    use Illuminate\Database\Eloquent\Factories\HasFactory;
    use Illuminate\Database\Eloquent\Model;
    $relationshipsUseStatements
    
    class $name extends Model
    {
        use HasFactory;
    
        $fillableContent
    
        $relationshipMethods
    }
    ";
    
        File::ensureDirectoryExists(dirname($modelPath));
        File::put($modelPath, $content);
    }
    
    

    private function getRelationshipMethodName($relation)
    {
        $modelName = Str::camel($relation['model']);
        return in_array($relation['type'], ['hasMany', 'belongsToMany', 'hasManyThrough', 'morphMany']) ? 
            Str::lower($modelName) : $modelName;
    }

    private function generateRelationshipMethod($relation, $methodName)
    {
        $modelClass = $relation['model'];
        $type = $relation['type'];
        $details = $relation['details'];
    
        switch ($type) {
            case 'hasOne':
                return "\n    public function $methodName()\n    {\n        return \$this->hasOne(App\Models\\$modelClass::class);\n    }\n";
    
            case 'hasMany':
                return "\n    public function $methodName()\n    {\n        return \$this->hasMany(App\Models\\$modelClass::class);\n    }\n";
    
            case 'belongsTo':
                $foreignKey = $details['foreign_key'] ?? null;
                $foreignKeyParam = $foreignKey ? ", '$foreignKey'" : '';
                return "\n    public function $methodName()\n    {\n        return \$this->belongsTo(App\Models\\$modelClass::class$foreignKeyParam);\n    }\n";
    
            case 'belongsToMany':
                $pivotTable = $details['pivot_table'];
                $foreignPivotKey = $details['foreign_pivot_key'];
                $relatedPivotKey = $details['related_pivot_key'];
                return "\n    public function $methodName()\n    {\n        return \$this->belongsToMany(App\Models\\$modelClass::class, '$pivotTable', '$foreignPivotKey', '$relatedPivotKey');\n    }\n";
    
            case 'hasOneThrough':
                $throughModel = $details['through_model'];
                $firstKey = $details['first_key'] ? ", '{$details['first_key']}'" : '';
                $secondKey = $details['second_key'] ? ", '{$details['second_key']}'" : '';
                return "\n    public function $methodName()\n    {\n        return \$this->hasOneThrough(App\Models\\$modelClass::class, App\Models\\$throughModel::class$firstKey$secondKey);\n    }\n";
    
            case 'hasManyThrough':
                $throughModel = $details['through_model'];
                $firstKey = $details['first_key'] ? ", '{$details['first_key']}'" : '';
                $secondKey = $details['second_key'] ? ", '{$details['second_key']}'" : '';
                return "\n    public function $methodName()\n    {\n        return \$this->hasManyThrough(App\Models\\$modelClass::class, App\Models\\$throughModel::class$firstKey$secondKey);\n    }\n";
    
            case 'morphOne':
                $name = Str::snake($methodName);
                return "\n    public function $methodName()\n    {\n        return \$this->morphOne(App\Models\\$modelClass::class, '$name');\n    }\n";
    
            case 'morphMany':
                $name = Str::snake($methodName);
                return "\n    public function $methodName()\n    {\n        return \$this->morphMany(App\Models\\$modelClass::class, '$name');\n    }\n";
    
            case 'morphTo':
                $name = Str::snake($methodName);
                return "\n    public function $methodName()\n    {\n        return \$this->morphTo('$name');\n    }\n";
    
            default:
                return "";
        }
    }

    private function generateMigration($name, $columns)
    {
        $tableName = Str::snake(Str::plural($name));
        $migrationName = "create_{$tableName}_table";

        Artisan::call("make:migration $migrationName --create=$tableName");

        $migrationFile = collect(File::allFiles(database_path('migrations')))
            ->last(fn($file) => Str::contains($file->getFilename(), $migrationName));

        $migrationPath = $migrationFile->getPathname();

        $schema = '';
        foreach ($columns as $column) {
            $nullable = $column['nullable'] ? '->nullable()' : '';
            $columnDefinition = "\$table->{$column['type']}('{$column['name']}')$nullable;";

            if (isset($column['isForeignKey']) && $column['isForeignKey']) {
                $columnDefinition = "\$table->foreignId('{$column['name']}')$nullable;";
                $foreignKey = "\$table->foreign('{$column['name']}')
                    ->references('{$column['referenceColumn']}')
                    ->on('{$column['referenceTable']}')
                    ->onDelete('cascade');";
                $schema .= "$columnDefinition\n            $foreignKey\n";
            } else {
                $schema .= "            $columnDefinition\n";
            }
        }

        $migrationContent = File::get($migrationPath);
        
        $migrationContent = preg_replace(
            '/Schema::create\(.*?function \(Blueprint \$table\) \{.*?\}/s',
            "Schema::create('$tableName', function (Blueprint \$table) {
            \$table->id();
$schema            \$table->timestamps();
        }",
            $migrationContent
        );

        File::put($migrationPath, $migrationContent);
    }

    private function generateBaseRepository()
    {
        $path = app_path('Repositories/BaseRepository.php');
        if (!File::exists($path)) {
            $content = "<?php
    
    namespace App\Repositories;
    
    use Illuminate\Database\Eloquent\Model;
    
    abstract class BaseRepository
    {
        protected \$model;
    
        public function __construct(Model \$model)
        {
            \$this->model = \$model;
        }
    
        public function all(array \$relations = [])
        {
            \$query = \$this->model->newQuery();
    
            if (!empty(\$relations)) {
                \$query->with(\$relations);
            }
    
            return \$query->get();
        }
    
        public function find(\$id, array \$relations = [])
        {
            \$query = \$this->model->newQuery();
    
            if (!empty(\$relations)) {
                \$query->with(\$relations);
            }
    
            return \$query->findOrFail(\$id);
        }
    
        public function create(array \$data)
        {
            return \$this->model->create(\$data);
        }
    
        public function update(\$id, array \$data)
        {
            \$model = \$this->find(\$id);
            \$model->update(\$data);
            return \$model;
        }
    
        public function delete(\$id)
        {
            \$model = \$this->find(\$id);
            \$model->delete();
            return true;
        }
    }
    ";
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $content);
            $this->info('BaseRepository created.');
        }
    }
    
    private function generateRepository($name)
    {
        $repositoryPath = app_path("Repositories/{$name}Repository.php");
        $modelClass = "App\\Models\\$name";
    
        $content = "<?php
    
    namespace App\Repositories;
    
    use $modelClass;
    
    class {$name}Repository extends BaseRepository
    {
        public function __construct($name \$model)
        {
            parent::__construct(\$model);
        }
    
        public function all(array \$relations = [])
        {
            return parent::all(\$relations);
        }
    
    }
    ";
        File::ensureDirectoryExists(dirname($repositoryPath));
        File::put($repositoryPath, $content);
        $this->info("Repository created: $repositoryPath");
    }
    

    private function generateController($name, $fillable)
    {
        $controllerPath = app_path("Http/Controllers/{$name}Controller.php");
        $repositoryClass = "App\\Repositories\\{$name}Repository";
    
        $relations = [];
        if ($this->confirm('Does the model have any relationships? (yes/no)', true)) {
            $relationsInput = $this->ask('Please enter the relationship names, separated by commas (e.g., posts,comments)');
            
            $relations = array_map('trim', explode(',', $relationsInput));
        }
    
        $relationsString = !empty($relations) ? "['" . implode("', '", $relations) . "']" : '[]';
    
        $content = "<?php
    
    namespace App\Http\Controllers;
    
    use Illuminate\Http\Request;
    use $repositoryClass;
    
    class {$name}Controller extends Controller
    {
        protected \$repository;
    
        public function __construct({$name}Repository \$repository)
        {
            \$this->repository = \$repository;
        }
    
        public function index(Request \$request)
        {
            \$query = \$this->repository->all($relationsString);  
    
            if (\$request->has('search')) {
                \$query = \$query->where('name', 'like', '%' . \$request->search . '%');
            }
    
            if (\$request->has('filter')) {
                // Apply your filter logic here
            }
    
            return view('" . Str::lower(Str::plural($name)) . ".index', compact('query'));
        }
    
        public function create()
        {
            return view('" . Str::lower(Str::plural($name)) . ".create');
        }
    
        public function store(Request \$request)
        {
            \$data = \$request->all();
    
            \$this->repository->create(\$data);
    
            return redirect()->route('" . Str::lower(Str::plural($name)) . ".index')->with('success', '{$name} created successfully.');
        }
    
        public function edit(\$id)
        {
            \$item = \$this->repository->find(\$id, $relationsString);  // Relationships included directly in the find() method
            return view('" . Str::lower(Str::plural($name)) . ".edit', compact('item'));
        }
    
        public function update(Request \$request, \$id)
        {
                       \$data = \$request->all();

    
            \$this->repository->update(\$id, \$data);
    
            return redirect()->route('" . Str::lower(Str::plural($name)) . ".index')->with('success', '{$name} updated successfully.');
        }
    
        public function destroy(\$id)
        {
            \$this->repository->delete(\$id);
    
            return redirect()->route('" . Str::lower(Str::plural($name)) . ".index')->with('success', '{$name} deleted successfully.');
        }
    }
    ";
    
        File::put($controllerPath, $content);
        $this->info('Controller created successfully.');
    }
    
    

private function generateLayout($name)
{
    $layoutPath = resource_path('views/layouts/app.blade.php');

    if (File::exists($layoutPath)) {
        $existingContent = File::get($layoutPath);

        $menuItem = '<a href="{{ route(\''.Str::lower(Str::plural($name)).'.index\') }}" class="nav-link">';
        if (strpos($existingContent, $menuItem) !== false) {
            return; 
        }

        $navStart = strpos($existingContent, '<ul class="nav nav-pills nav-sidebar flex-column"');
        $insertPosition = strpos($existingContent, '</ul>', $navStart);

        $newMenuItem = '
        <li class="nav-item">
            <a href="{{ route(\''.Str::lower(Str::plural($name)).'.index\') }}" class="nav-link">
                <i class="nav-icon fas fa-cogs"></i>
                <p>' . $name . ' Management</p>
            </a>
        </li>';

        $updatedContent = substr_replace($existingContent, $newMenuItem . "\n", $insertPosition, 0);

        File::put($layoutPath, $updatedContent);
    } else {
        $layoutContent = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield("title", "admin")</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/admin-lte/3.2.0/css/adminlte.min.css">
    @yield("styles")
</head>
<body class="hold-transition sidebar-mini">
    <div class="wrapper">
        <nav class="main-header navbar navbar-expand navbar-white navbar-light">
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
                </li>
            </ul>
            <ul class="navbar-nav ml-auto">
                <li class="nav-item">
                    <a class="nav-link" data-widget="fullscreen" href="#" role="button">
                        <i class="fas fa-expand-arrows-alt"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <aside class="main-sidebar sidebar-dark-primary elevation-4">
            <a href="/" class="brand-link">
                <img src="https://cdnjs.cloudflare.com/ajax/libs/admin-lte/3.2.0/img/AdminLTELogo.png" alt="Logo" class="brand-image img-circle elevation-3" style="opacity: .8">
                <span class="brand-text font-weight-light">AdminLTE 3</span>
            </a>
            <div class="sidebar">
                <nav class="mt-2">
                    <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu">
                        <li class="nav-item">
                            <a href="#" class="nav-link">
                                <i class="nav-icon fas fa-tachometer-alt"></i>
                                <p>Dashboard</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route(\''.Str::lower(Str::plural($name)).'.index\') }}" class="nav-link">
                                <i class="nav-icon fas fa-cogs"></i>
                                <p>' . $name . ' Management</p>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        </aside>
        <div class="content-wrapper">
            <section class="content-header">
                <div class="container-fluid">
                    <div class="row mb-2">
                        <div class="col-sm-6">
                            <h1>@yield("page_title", "Page Title")</h1>
                        </div>
                    </div>
                </div>
            </section>
            <section class="content">
                <div class="container-fluid">
                    @yield("content")
                </div>
            </section>
        </div>
        <footer class="main-footer">
            <div class="float-right d-none d-sm-inline">
                Anything you want
            </div>
            <strong>&copy; 2024</strong> All rights reserved.
        </footer>
    </div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/admin-lte/3.2.0/js/adminlte.min.js"></script>
    @yield("scripts")
</body>
</html>';

        File::ensureDirectoryExists(dirname($layoutPath));
        File::put($layoutPath, $layoutContent);
    }
}


private function generateViews($name, $fillable, $attributeTypes)
{
    $viewsPath = resource_path("views/" . Str::lower(Str::plural($name)));
    
    File::ensureDirectoryExists($viewsPath);

    $indexView = $viewsPath . '/index.blade.php';
    $indexContent = "@extends('layouts.app')

@section('content')
    <div class=\"container\">
        <h1>List of " . Str::plural($name) . "</h1>
        <a href=\"{{ route('" . Str::lower(Str::plural($name)) . ".create') }}\" class=\"btn btn-primary\">Create New</a>
        <table class=\"table\">
            <thead>
                <tr>";
    foreach ($fillable as $attr) {
        $indexContent .= "<th>{{ ucfirst('$attr') }}</th>";
    }
    $indexContent .= "
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
            @foreach(\$query as \$item)
                <tr>";
    foreach ($fillable as $attr) {
        $indexContent .= "<td>{{ \$item->$attr }}</td>";
    }
    $indexContent .= "
                <td>
                    <a href=\"{{ route('" . Str::lower(Str::plural($name)) . ".edit', \$item->id) }}\" class=\"btn btn-warning\">Edit</a>
                    <form action=\"{{ route('" . Str::lower(Str::plural($name)) . ".destroy', \$item->id) }}\" method=\"POST\" style=\"display:inline\">
                        @csrf
                        @method('DELETE')
                        <button type=\"submit\" class=\"btn btn-danger\">Delete</button>
                    </form>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
";
    File::put($indexView, $indexContent);

    // Create View
    $createView = $viewsPath . '/create.blade.php';
    $createContent = "@extends('layouts.app')

@section('content')
    <div class=\"container\">
        <h1>Create " . Str::singular($name) . "</h1>
        <form action=\"{{ route('" . Str::lower(Str::plural($name)) . ".store') }}\" method=\"POST\">
            @csrf";
    foreach ($fillable as $attr) {
        $type = $attributeTypes[$attr];
        
        $createContent .= "
            <div class=\"form-group\">
                <label for=\"$attr\">" . ucfirst($attr) . "</label>";
        
        if ($type == 'select') {
            $createContent .= "<select name=\"$attr\" id=\"$attr\" class=\"form-control\" required>
                                <option value=\"\">Select " . ucfirst($attr) . "</option>
                                <!-- Add options dynamically -->
                            </select>";
        } elseif ($type == 'number') {
            $createContent .= "<input type=\"number\" name=\"$attr\" id=\"$attr\" class=\"form-control\" required>";
        } elseif ($type == 'textarea') {
            $createContent .= "<textarea name=\"$attr\" id=\"$attr\" class=\"form-control\" rows=\"4\" required></textarea>";
        } elseif ($type == 'password') {
            $createContent .= "<input type=\"password\" name=\"$attr\" id=\"$attr\" class=\"form-control\" required>";
        } elseif ($type == 'tel') {
            $createContent .= "<input type=\"tel\" name=\"$attr\" id=\"$attr\" class=\"form-control\" required>";
        } else {
            $createContent .= "<input type=\"text\" name=\"$attr\" id=\"$attr\" class=\"form-control\" required>";
        }

        $createContent .= "</div>";
    }
    $createContent .= "
            <button type=\"submit\" class=\"btn btn-primary\">Submit</button>
        </form>
    </div>
@endsection
";
    File::put($createView, $createContent);

    $editView = $viewsPath . '/edit.blade.php';
    $editContent = "@extends('layouts.app')

@section('content')
    <div class=\"container\">
        <h1>Edit " . Str::singular($name) . "</h1>
        <form action=\"{{ route('" . Str::lower(Str::plural($name)) . ".update', \$item->id) }}\" method=\"POST\">
            @csrf
            @method('PUT')";
    foreach ($fillable as $attr) {
        $type = $attributeTypes[$attr]; 
        
        $editContent .= "
            <div class=\"form-group\">
                <label for=\"$attr\">" . ucfirst($attr) . "</label>";
        
        if ($type == 'select') {
            $editContent .= "<select name=\"$attr\" id=\"$attr\" class=\"form-control\" required>
                                <option value=\"\">Select " . ucfirst($attr) . "</option>
                                <!-- Add options dynamically -->
                            </select>";
        } elseif ($type == 'number') {
            $editContent .= "<input type=\"number\" name=\"$attr\" id=\"$attr\" value=\"{{ \$item->$attr }}\" class=\"form-control\" required>";
        } elseif ($type == 'textarea') {
            $editContent .= "<textarea name=\"$attr\" id=\"$attr\" class=\"form-control\" rows=\"4\" required>{{ \$item->$attr }}</textarea>";
        } elseif ($type == 'password') {
            $editContent .= "<input type=\"password\" name=\"$attr\" id=\"$attr\" value=\"{{ \$item->$attr }}\" class=\"form-control\" required>";
        } elseif ($type == 'tel') {
            $editContent .= "<input type=\"tel\" name=\"$attr\" id=\"$attr\" value=\"{{ \$item->$attr }}\" class=\"form-control\" required>";
        } else {
            $editContent .= "<input type=\"text\" name=\"$attr\" id=\"$attr\" value=\"{{ \$item->$attr }}\" class=\"form-control\" required>";
        }

        $editContent .= "</div>";
    }
    $editContent .= "
            <button type=\"submit\" class=\"btn btn-primary\">Submit</button>
        </form>
    </div>
@endsection
";
    File::put($editView, $editContent);

    $showView = $viewsPath . '/show.blade.php';
    $showContent = "@extends('layouts.app')

@section('content')
    <div class=\"container\">
        <h1>" . Str::singular($name) . " Details</h1>
        <table class=\"table\">
            <tbody>";
    foreach ($fillable as $attr) {
        $showContent .= "
                <tr>
                    <th>{{ ucfirst('$attr') }}</th>
                    <td>{{ \$item->$attr }}</td>
                </tr>";
    }
    $showContent .= "
            </tbody>
        </table>
    </div>
@endsection
";
    File::put($showView, $showContent);
}

    private function generateRoutes($name)
    {
        $routePath = base_path('routes/web.php');
        $routeContent = "\nRoute::resource('".Str::lower(Str::plural($name))."', App\Http\Controllers\\{$name}Controller::class);";
        File::append($routePath, $routeContent);
    }
}
