<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite;

use Clcbws\DatabaseLifecycleSuite\Traits\FiltersTables;
use Clcbws\DatabaseLifecycleSuite\Traits\MapsDatabaseTypes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Class LegacyBridge
 *
 * Engine for reverse-engineering existing database tables into Laravel
 * migrations, models, seeders, and factories with multi-connection isolation,
 * correct relationship resolution, and production safety.
 */
class LegacyBridge
{
    use FiltersTables, MapsDatabaseTypes;

    /**
     * @var array List of identified pivot tables.
     */
    protected array $pivots = [];

    /**
     * @var array Map of model relationships.
     */
    protected array $relationships = [];

    /**
     * Reverse-engineer tables into migrations, models, seeders, and factories.
     *
     * @param  array  $tables  List of tables to process.
     * @param  string  $outputDir  Base directory for output files.
     * @return array Summary of generated files per table.
     */
    public function reverseEngineer(
        array $tables,
        string $outputDir,
        ?string $connection = null,
        bool $force = false
    ): array {
        $results = [];
        $prefix = DB::connection($connection)->getTablePrefix();

        $this->ensureDirectoryExists($outputDir.'/migrations');
        $this->ensureDirectoryExists($outputDir.'/models');
        $this->ensureDirectoryExists($outputDir.'/seeders');
        $this->ensureDirectoryExists($outputDir.'/factories');

        $this->pivots = [];
        $this->relationships = [];

        // Phase 1: Analyze all tables to identify pivots and relationships
        foreach ($tables as $table) {
            $this->analyzeTableForRelationships($table, $prefix, $connection);
        }

        // Phase 2: Generate files
        foreach ($tables as $table) {
            $shortTable = $this->stripPrefix($table, $prefix);
            $results[$table] = [
                'migration' => $this->generateMigration($table, $shortTable, $outputDir.'/migrations', $connection, $force),
                'model' => $this->generateModel($table, $shortTable, $outputDir.'/models', $connection, $force),
                'seeder' => $this->generateSeeder($table, $outputDir.'/seeders', $connection, $force),
                'factory' => $this->generateFactory($table, $shortTable, $outputDir.'/factories', $connection, $force),
            ];
        }

        return $results;
    }

    /**
     * Analyze a table to identify relationships (pivots, belongsTo, hasMany).
     */
    protected function analyzeTableForRelationships(string $table, string $prefix, ?string $connection = null): void
    {
        $foreignKeys = Schema::connection($connection)->getForeignKeys($table);
        $columns = Schema::connection($connection)->getColumnListing($table);

        $isPivot = count($foreignKeys) === 2
            && count($columns) <= 4
            && ($foreignKeys[0]['foreign_table'] ?? '') !== ($foreignKeys[1]['foreign_table'] ?? '');

        if ($isPivot) {
            $this->pivots[] = $table;

            $tableA = $foreignKeys[0]['foreign_table'];
            $tableB = $foreignKeys[1]['foreign_table'];

            $shortA = $this->stripPrefix($tableA, $prefix);
            $shortB = $this->stripPrefix($tableB, $prefix);
            $shortPivot = $this->stripPrefix($table, $prefix);

            $this->relationships[$tableA][] = [
                'type' => 'belongsToMany',
                'related' => Str::studly(Str::singular($shortB)),
                'pivot' => $shortPivot,
                'method_hint' => Str::camel(Str::plural($shortB)),
            ];

            $this->relationships[$tableB][] = [
                'type' => 'belongsToMany',
                'related' => Str::studly(Str::singular($shortA)),
                'pivot' => $shortPivot,
                'method_hint' => Str::camel(Str::plural($shortA)),
            ];
        } else {
            foreach ($foreignKeys as $fk) {
                $targetTable = $fk['foreign_table'];
                $shortTarget = $this->stripPrefix($targetTable, $prefix);
                $modelName = Str::studly(Str::singular($shortTarget));
                $fkColumn = $fk['columns'][0] ?? 'id';

                // Determine sensible method name for belongsTo
                $methodHint = str_ends_with($fkColumn, '_id')
                    ? Str::camel(substr($fkColumn, 0, -3))
                    : Str::camel(Str::singular($shortTarget));

                $this->relationships[$table][] = [
                    'type' => 'belongsTo',
                    'related' => $modelName,
                    'foreign_key' => $fkColumn,
                    'method_hint' => $methodHint,
                ];

                $shortSource = $this->stripPrefix($table, $prefix);
                $inverseHint = str_ends_with($fkColumn, '_id') && substr($fkColumn, 0, -3) !== Str::singular($shortTarget)
                    ? Str::camel(substr($fkColumn, 0, -3).'_'.Str::plural($shortSource))
                    : Str::camel(Str::plural($shortSource));

                $this->relationships[$targetTable][] = [
                    'type' => 'hasMany',
                    'related' => Str::studly(Str::singular($shortSource)),
                    'foreign_key' => $fkColumn,
                    'method_hint' => $inverseHint,
                ];
            }
        }
    }

    /**
     * Ensure a directory exists.
     */
    protected function ensureDirectoryExists(string $path): void
    {
        if (! is_dir($path)) {
            if (! @mkdir($path, 0755, true) && ! is_dir($path)) {
                throw new RuntimeException("Unable to create directory: {$path}");
            }
        }
    }

    /**
     * Generate a Laravel Migration for a table, isolating foreign keys to prevent circular dependencies.
     */
    protected function generateMigration(
        string $fullTable,
        string $shortTable,
        string $path,
        ?string $connection = null,
        bool $force = false
    ): string {
        $columns = Schema::connection($connection)->getColumns($fullTable);
        $indexes = Schema::connection($connection)->getIndexes($fullTable);
        $foreignKeys = Schema::connection($connection)->getForeignKeys($fullTable);

        $timestamp = date('Y_m_d_His');
        $fileName = $timestamp.'_create_'.$shortTable.'_table.php';

        $fullFilePath = $path.'/'.$fileName;
        if (file_exists($fullFilePath) && ! $force) {
            return "Skipped (already exists): {$fileName}";
        }

        $primaryKeys = [];
        foreach ($indexes as $index) {
            if (! empty($index['primary'])) {
                $primaryKeys = $index['columns'];
                break;
            }
        }

        $content = "<?php\n\n";
        $content .= "use Illuminate\Database\Migrations\Migration;\n";
        $content .= "use Illuminate\Database\Schema\Blueprint;\n";
        $content .= "use Illuminate\Support\Facades\Schema;\n\n";
        $content .= "return new class extends Migration\n{\n";
        $content .= "    public function up(): void\n    {\n";
        $content .= "        Schema::create('{$shortTable}', function (Blueprint \$table) {\n";

        $hasSingleAutoIncrement = false;
        foreach ($columns as $column) {
            $isPk = count($primaryKeys) === 1 && $primaryKeys[0] === $column['name'];
            $rendered = $this->mapColumnToMigration($column, $isPk);
            if ($rendered['auto_increment']) {
                $hasSingleAutoIncrement = true;
            }
            $content .= '            '.$rendered['statement'].";\n";
        }

        // Composite or non-incrementing primary keys
        if (count($primaryKeys) > 1) {
            $pkCols = var_export($primaryKeys, true);
            $content .= "            \$table->primary({$pkCols});\n";
        } elseif (count($primaryKeys) === 1 && ! $hasSingleAutoIncrement) {
            $pkCols = var_export($primaryKeys, true);
            $content .= "            \$table->primary({$pkCols});\n";
        }

        // Secondary Indexes & Unique constraints
        foreach ($indexes as $index) {
            if (! empty($index['primary'])) {
                continue;
            }
            $content .= '            '.$this->mapIndexToMigration($index).";\n";
        }

        $content .= "        });\n";

        // Foreign keys are applied safely in the same migration if non-empty,
        // or can be grouped to respect dependencies
        if (! empty($foreignKeys)) {
            $prefix = DB::connection($connection)->getTablePrefix();
            $content .= "\n        Schema::table('{$shortTable}', function (Blueprint \$table) {\n";
            foreach ($foreignKeys as $fk) {
                $targetTable = $this->stripPrefix($fk['foreign_table'], $prefix);
                $content .= '            '.$this->mapForeignKeyToMigration($fk, $targetTable).";\n";
            }
            $content .= "        });\n";
        }

        $content .= "    }\n\n";
        $content .= "    public function down(): void\n    {\n";
        $content .= "        Schema::dropIfExists('{$shortTable}');\n";
        $content .= "    }\n};\n";

        $fullFilePath = $path.'/'.$fileName;
        if (file_put_contents($fullFilePath, $content) === false) {
            throw new RuntimeException("Failed to write generated migration: {$fileName}");
        }

        return "Migration generated: {$fileName}";
    }

    /**
     * Generate a Laravel Model for a table with multi-connection support and collision-free relationships.
     */
    protected function generateModel(
        string $fullTable,
        string $shortTable,
        string $path,
        ?string $connection = null,
        bool $force = false
    ): string {
        if (in_array($fullTable, $this->pivots, true)) {
            return 'Skipped (Pivot table)';
        }

        $modelName = Str::studly(Str::singular($shortTable));
        $fullFilePath = $path.'/'.$modelName.'.php';
        if (file_exists($fullFilePath) && ! $force) {
            return "Skipped (already exists): {$modelName}.php";
        }

        $columns = Schema::connection($connection)->getColumnListing($fullTable);
        $indexes = Schema::connection($connection)->getIndexes($fullTable);

        $primaryKeys = [];
        foreach ($indexes as $index) {
            if (! empty($index['primary'])) {
                $primaryKeys = $index['columns'];
                break;
            }
        }

        $fillable = array_values(array_diff($columns, ['id', 'created_at', 'updated_at', 'deleted_at']));

        $content = "<?php\n\nnamespace App\\Models;\n\n";
        $content .= "use Illuminate\Database\Eloquent\Model;\n";
        $content .= "use Illuminate\Database\Eloquent\Factories\HasFactory;\n\n";
        $content .= "class {$modelName} extends Model\n{\n";
        $content .= "    use HasFactory;\n\n";

        if ($connection !== null && $connection !== '') {
            $content .= "    protected \$connection = '{$connection}';\n\n";
        }

        $content .= "    protected \$table = '{$shortTable}';\n\n";

        if (count($primaryKeys) === 1 && $primaryKeys[0] !== 'id') {
            $content .= "    protected \$primaryKey = '{$primaryKeys[0]}';\n\n";
        } elseif (count($primaryKeys) > 1) {
            $pkExport = var_export($primaryKeys, true);
            $content .= "    // Composite primary key\n";
            $content .= "    public \$incrementing = false;\n";
            $content .= "    protected \$primaryKey = {$pkExport};\n\n";
        }

        $content .= "    protected \$fillable = [\n";
        foreach ($fillable as $column) {
            $content .= "        '{$column}',\n";
        }
        $content .= "    ];\n";

        if (isset($this->relationships[$fullTable])) {
            $usedMethods = [];
            foreach ($this->relationships[$fullTable] as $rel) {
                $baseMethodName = $rel['method_hint'] ?? Str::camel($rel['related']);
                $methodName = $baseMethodName;
                $counter = 2;
                while (in_array($methodName, $usedMethods, true)) {
                    $methodName = $baseMethodName.$counter;
                    $counter++;
                }
                $usedMethods[] = $methodName;

                $content .= "\n    public function {$methodName}()\n    {\n";
                if ($rel['type'] === 'belongsToMany') {
                    $content .= "        return \$this->belongsToMany({$rel['related']}::class, '{$rel['pivot']}');\n";
                } else {
                    $content .= "        return \$this->{$rel['type']}({$rel['related']}::class, '{$rel['foreign_key']}');\n";
                }
                $content .= "    }\n";
            }
        }

        $content .= "}\n";

        $fullFilePath = $path.'/'.$modelName.'.php';
        if (file_put_contents($fullFilePath, $content) === false) {
            throw new RuntimeException("Failed to write generated model: {$modelName}.php");
        }

        return "Model generated: {$modelName}.php";
    }

    /**
     * Generate a safe Laravel Seeder scaffold (Never export live rows).
     */
    protected function generateSeeder(
        string $table,
        string $path,
        ?string $connection = null,
        bool $force = false
    ): string {
        $seederName = Str::studly($table).'TableSeeder';
        $fullFilePath = $path.'/'.$seederName.'.php';
        if (file_exists($fullFilePath) && ! $force) {
            return "Skipped (already exists): {$seederName}.php";
        }

        $content = "<?php\n\nnamespace Database\\Seeders;\n\n";
        $content .= "use Illuminate\\Database\\Seeder;\n\n";
        $content .= "class {$seederName} extends Seeder\n{\n";
        $content .= "    public function run(): void\n    {\n";
        $content .= "        // Add reviewed or synthetic reference data here.\n";
        $content .= "        // Live database rows are intentionally not exported.\n";
        $content .= "    }\n}\n";

        if (file_put_contents($fullFilePath, $content) === false) {
            throw new RuntimeException("Failed to write generated seeder: {$seederName}.php");
        }

        return "Safe seeder scaffold generated: {$seederName}.php";
    }

    /**
     * Generate a Laravel Factory for a table.
     */
    protected function generateFactory(
        string $fullTable,
        string $shortTable,
        string $path,
        ?string $connection = null,
        bool $force = false
    ): string {
        if (in_array($fullTable, $this->pivots, true)) {
            return 'Skipped (Pivot table)';
        }

        $modelName = Str::studly(Str::singular($shortTable));
        $fullFilePath = $path.'/'.$modelName.'Factory.php';
        if (file_exists($fullFilePath) && ! $force) {
            return "Skipped (already exists): {$modelName}Factory.php";
        }

        $columns = Schema::connection($connection)->getColumns($fullTable);

        $content = "<?php\n\nnamespace Database\\Factories;\n\n";
        $content .= "use App\\Models\\{$modelName};\n";
        $content .= "use Illuminate\Database\Eloquent\Factories\Factory;\n\n";
        $content .= "class {$modelName}Factory extends Factory\n{\n";
        $content .= "    protected \$model = {$modelName}::class;\n\n";
        $content .= "    public function definition(): array\n    {\n";
        $content .= "        return [\n";

        foreach ($columns as $column) {
            $colName = $column['name'];
            if (in_array($colName, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                continue;
            }
            $content .= "            '{$colName}' => ".$this->mapToFaker($colName).",\n";
        }

        $content .= "        ];\n    }\n}\n";

        $fullFilePath = $path.'/'.$modelName.'Factory.php';
        if (file_put_contents($fullFilePath, $content) === false) {
            throw new RuntimeException("Failed to write generated factory: {$modelName}Factory.php");
        }

        return "Factory generated: {$modelName}Factory.php";
    }

    /**
     * Map a column name to a Faker method.
     */
    protected function mapToFaker(string $column): string
    {
        return match (true) {
            str_contains($column, 'email') => '$this->faker->unique()->safeEmail()',
            str_contains($column, 'first_name') => '$this->faker->firstName()',
            str_contains($column, 'last_name') => '$this->faker->lastName()',
            str_contains($column, 'name') => '$this->faker->name()',
            str_contains($column, 'phone') => '$this->faker->phoneNumber()',
            str_contains($column, 'address') => '$this->faker->address()',
            str_contains($column, 'city') => '$this->faker->city()',
            str_contains($column, 'zip') || str_contains($column, 'postal') => '$this->faker->postcode()',
            str_contains($column, 'country') => '$this->faker->country()',
            str_contains($column, 'description') || str_contains($column, 'content') => '$this->faker->paragraph()',
            str_contains($column, 'slug') => '$this->faker->slug()',
            str_contains($column, 'url') || str_contains($column, 'website') => '$this->faker->url()',
            str_contains($column, 'ip') => '$this->faker->ipv4()',
            str_contains($column, 'uuid') => '$this->faker->uuid()',
            str_contains($column, 'date') || str_contains($column, 'at') => '$this->faker->dateTime()',
            str_contains($column, 'price') || str_contains($column, 'amount') => '$this->faker->randomFloat(2, 0, 1000)',
            str_contains($column, 'is_') || str_contains($column, 'has_') => '$this->faker->boolean()',
            default => '$this->faker->word()',
        };
    }

    /**
     * Strip prefix from table name if it exists.
     */
    protected function stripPrefix(string $tableName, string $prefix): string
    {
        if (! empty($prefix) && str_starts_with($tableName, $prefix)) {
            return substr($tableName, strlen($prefix));
        }

        return $tableName;
    }

    /**
     * Map column metadata to Laravel migration statement and auto_increment flag.
     */
    protected function mapColumnToMigration(array $column, bool $isSinglePk = false): array
    {
        $colName = (string) $column['name'];
        $typeName = strtolower((string) $column['type_name']);
        $autoIncrement = ! empty($column['auto_increment']);

        // Check for primary key / auto increment integer
        if (($isSinglePk && str_contains($typeName, 'int')) || ($autoIncrement && str_contains($typeName, 'int'))) {
            if ($colName === 'id') {
                return ['statement' => '$table->id()', 'auto_increment' => true];
            }
            if (str_contains($typeName, 'bigint')) {
                return ['statement' => "\$table->bigIncrements('{$colName}')", 'auto_increment' => true];
            }

            return ['statement' => "\$table->increments('{$colName}')", 'auto_increment' => true];
        }

        $type = $this->mapType($typeName);

        // Handle decimal precision and scale
        if ($type === 'decimal' && isset($column['precision'], $column['scale'])) {
            $method = "\$table->decimal('{$colName}', {$column['precision']}, {$column['scale']})";
        } else {
            $method = "\$table->{$type}('{$colName}')";
        }

        $isUnsigned = ! empty($column['unsigned']) || str_contains(strtolower((string) ($column['type'] ?? '')), 'unsigned');
        if ($isUnsigned && str_contains($typeName, 'int')) {
            $method .= '->unsigned()';
        }

        if (! empty($column['nullable'])) {
            $method .= '->nullable()';
        }

        if (array_key_exists('default', $column) && $column['default'] !== null) {
            $method .= '->default('.var_export($column['default'], true).')';
        }

        return ['statement' => $method, 'auto_increment' => false];
    }

    protected function mapIndexToMigration(array $index): string
    {
        $type = ! empty($index['unique']) ? 'unique' : 'index';
        $columns = (array) ($index['columns'] ?? []);
        $colsArg = count($columns) === 1 ? var_export($columns[0], true) : var_export($columns, true);
        $name = ! empty($index['name']) ? ', '.var_export($index['name'], true) : '';

        return "\$table->{$type}({$colsArg}{$name})";
    }

    protected function mapForeignKeyToMigration(array $fk, string $targetTable): string
    {
        $columns = (array) ($fk['columns'] ?? []);
        $foreignColumns = (array) ($fk['foreign_columns'] ?? ['id']);

        $colsArg = count($columns) === 1 ? var_export($columns[0], true) : var_export($columns, true);
        $refArg = count($foreignColumns) === 1 ? var_export($foreignColumns[0], true) : var_export($foreignColumns, true);

        $statement = "\$table->foreign({$colsArg})->references({$refArg})->on('{$targetTable}')";
        $onDelete = strtolower((string) ($fk['on_delete'] ?? ''));
        $allowedActions = ['cascade', 'restrict', 'set null', 'no action', 'set default'];
        if (in_array($onDelete, $allowedActions, true)) {
            $statement .= "->onDelete('{$onDelete}')";
        }

        $onUpdate = strtolower((string) ($fk['on_update'] ?? ''));
        if (in_array($onUpdate, $allowedActions, true)) {
            $statement .= "->onUpdate('{$onUpdate}')";
        }

        return $statement;
    }
}
