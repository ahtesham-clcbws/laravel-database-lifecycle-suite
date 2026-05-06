<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Clcbws\DatabaseLifecycleSuite\Traits\MapsDatabaseTypes;

/**
 * Class LegacyBridge
 * 
 * Engine for reverse-engineering existing database tables into Laravel 
 * migrations, models, seeders, and factories.
 */
class LegacyBridge
{
    use MapsDatabaseTypes;

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
     * @param array $tables List of tables to process.
     * @param string $outputDir Base directory for output files.
     * @param string|null $connection
     * @return array Summary of generated files per table.
     */
    public function reverseEngineer(array $tables, string $outputDir, ?string $connection = null): array
    {
        $results = [];
        $prefix = DB::connection($connection)->getTablePrefix();

        $this->ensureDirectoryExists($outputDir . '/migrations');
        $this->ensureDirectoryExists($outputDir . '/models');
        $this->ensureDirectoryExists($outputDir . '/seeders');
        $this->ensureDirectoryExists($outputDir . '/factories');

        // Phase 1: Analyze all tables to identify pivots and relationships
        foreach ($tables as $table) {
            $this->analyzeTableForRelationships($table, $prefix, $connection);
        }

        // Phase 2: Generate files
        foreach ($tables as $table) {
            $shortTable = $this->stripPrefix($table, $prefix);
            $results[$table] = [
                'migration' => $this->generateMigration($table, $shortTable, $outputDir . '/migrations', $connection),
                'model' => $this->generateModel($table, $shortTable, $outputDir . '/models'),
                'seeder' => $this->generateSeeder($table, $outputDir . '/seeders', $connection),
                'factory' => $this->generateFactory($table, $shortTable, $outputDir . '/factories', $connection),
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

        if (count($foreignKeys) === 2 && count($columns) <= 3) {
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
            ];

            $this->relationships[$tableB][] = [
                'type' => 'belongsToMany',
                'related' => Str::studly(Str::singular($shortA)),
                'pivot' => $shortPivot,
            ];
        } else {
            // Check for potential belongsTo / hasMany
            foreach ($foreignKeys as $fk) {
                $targetTable = $fk['foreign_table'];
                $shortTarget = $this->stripPrefix($targetTable, $prefix);
                $modelName = Str::studly(Str::singular($shortTarget));

                $this->relationships[$table][] = [
                    'type' => 'belongsTo',
                    'related' => $modelName,
                    'foreign_key' => $fk['columns'][0],
                ];

                $this->relationships[$targetTable][] = [
                    'type' => 'hasMany',
                    'related' => Str::studly(Str::singular($this->stripPrefix($table, $prefix))),
                    'foreign_key' => $fk['columns'][0],
                ];
            }
        }
    }

    /**
     * Ensure a directory exists.
     */
    protected function ensureDirectoryExists(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    /**
     * Generate a Laravel Migration for a table.
     */
    protected function generateMigration(string $fullTable, string $shortTable, string $path, ?string $connection = null): string
    {
        $columns = Schema::connection($connection)->getColumns($fullTable);
        $indexes = Schema::connection($connection)->getIndexes($fullTable);
        $foreignKeys = Schema::connection($connection)->getForeignKeys($fullTable);

        $migrationName = 'create_' . $shortTable . '_table';
        $fileName = date('Y_m_d_His') . '_' . $migrationName . '.php';

        $content = "<?php\n\n";
        $content .= "use Illuminate\Database\Migrations\Migration;\n";
        $content .= "use Illuminate\Database\Schema\Blueprint;\n";
        $content .= "use Illuminate\Support\Facades\Schema;\n\n";
        $content .= "return new class extends Migration\n{\n";
        $content .= "    public function up()\n    {\n";
        $content .= "        Schema::create('{$shortTable}', function (Blueprint \$table) {\n";

        foreach ($columns as $column) {
            $content .= "            " . $this->mapColumnToMigration($column) . ";\n";
        }

        foreach ($indexes as $index) {
            if (!$index['primary']) {
                $content .= "            " . $this->mapIndexToMigration($index) . ";\n";
            }
        }

        foreach ($foreignKeys as $fk) {
            $prefix = DB::connection($connection)->getTablePrefix();
            $targetTable = $this->stripPrefix($fk['foreign_table'], $prefix);
            $content .= "            " . $this->mapForeignKeyToMigration($fk, $targetTable) . ";\n";
        }

        $content .= "        });\n    }\n\n";
        $content .= "    public function down()\n    {\n";
        $content .= "        Schema::dropIfExists('{$shortTable}');\n    }\n};\n";

        file_put_contents($path . '/' . $fileName, $content);

        return "Migration generated: {$fileName}";
    }

    /**
     * Generate a Laravel Model for a table.
     */
    protected function generateModel(string $fullTable, string $shortTable, string $path): string
    {
        if (in_array($fullTable, $this->pivots)) {
            return "Skipped (Pivot table)";
        }

        $modelName = Str::studly(Str::singular($shortTable));
        $columns = Schema::getColumnListing($fullTable);
        $fillable = array_diff($columns, ['id', 'created_at', 'updated_at']);

        $content = "<?php\n\nnamespace App\\Models;\n\n";
        $content .= "use Illuminate\Database\Eloquent\Model;\n";
        $content .= "use Illuminate\Database\Eloquent\Factories\HasFactory;\n\n";
        $content .= "class {$modelName} extends Model\n{\n";
        $content .= "    use HasFactory;\n\n";
        $content .= "    protected \$table = '{$shortTable}';\n\n";
        $content .= "    protected \$fillable = [\n";
        foreach ($fillable as $column) {
            $content .= "        '{$column}',\n";
        }
        $content .= "    ];\n";

        if (isset($this->relationships[$fullTable])) {
            foreach ($this->relationships[$fullTable] as $rel) {
                $methodName = $rel['type'] === 'hasMany' || $rel['type'] === 'belongsToMany' 
                    ? Str::camel(Str::plural($rel['related'])) 
                    : Str::camel($rel['related']);

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

        file_put_contents($path . '/' . $modelName . '.php', $content);

        return "Model generated: {$modelName}.php";
    }

    /**
     * Generate a Laravel Seeder for a table.
     */
    protected function generateSeeder(string $table, string $path, ?string $connection = null): string
    {
        $seederName = Str::studly($table) . 'TableSeeder';
        $data = DB::connection($connection)->table($table)->limit(100)->get();

        $content = "<?php\n\nnamespace Database\\Seeders;\n\n";
        $content .= "use Illuminate\Database\Seeder;\n";
        $content .= "use Illuminate\Support\Facades\DB;\n\n";
        $content .= "class {$seederName} extends Seeder\n{\n";
        $content .= "    public function run()\n    {\n";
        
        foreach ($data as $row) {
            $rowArray = var_export((array) $row, true);
            $content .= "        DB::table('{$table}')->insert({$rowArray});\n";
        }

        $content .= "    }\n}\n";

        file_put_contents($path . '/' . $seederName . '.php', $content);

        return "Seeder generated: {$seederName}.php";
    }

    /**
     * Generate a Laravel Factory for a table.
     */
    protected function generateFactory(string $fullTable, string $shortTable, string $path, ?string $connection = null): string
    {
        if (in_array($fullTable, $this->pivots)) {
            return "Skipped (Pivot table)";
        }

        $modelName = Str::studly(Str::singular($shortTable));
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
            if (in_array($colName, ['id', 'created_at', 'updated_at'])) {
                continue;
            }
            $content .= "            '{$colName}' => " . $this->mapToFaker($colName) . ",\n";
        }

        $content .= "        ];\n    }\n}\n";

        file_put_contents($path . '/' . $modelName . 'Factory.php', $content);

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
        if (!empty($prefix) && str_starts_with($tableName, $prefix)) {
            return substr($tableName, strlen($prefix));
        }
        return $tableName;
    }

    /**
     * Map column metadata to Laravel migration string.
     */
    protected function mapColumnToMigration(array $column): string
    {
        // Special case for Primary Key 'id'
        if ($column['name'] === 'id' && str_contains(strtolower($column['type_name']), 'int')) {
            return "\$table->id()";
        }

        $type = $this->mapType($column['type_name']);
        $method = "\$table->{$type}('{$column['name']}')";
        
        if ($column['nullable']) {
            $method .= "->nullable()";
        }
        
        if ($column['default'] !== null) {
            $method .= "->default(" . var_export($column['default'], true) . ")";
        }

        return $method;
    }

    protected function mapIndexToMigration(array $index): string
    {
        $type = $index['unique'] ? 'unique' : 'index';
        $columns = var_export($index['columns'], true);
        return "\$table->{$type}({$columns}, '{$index['name']}')";
    }

    protected function mapForeignKeyToMigration(array $fk, string $targetTable): string
    {
        $columns = var_export($fk['columns'], true);
        $references = $fk['foreign_columns'][0];
        
        return "\$table->foreign({$columns})->references('{$references}')->on('{$targetTable}')->onDelete('cascade')";
    }
}
