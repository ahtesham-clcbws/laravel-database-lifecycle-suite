<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class LifecycleComplexSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Users
        $userId1 = DB::table('users')->insertGetId([
            'name' => 'Fictional Alice Admin',
            'email' => 'alice@fictional-domain.test',
            'password' => Hash::make('secret-hash-123'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userId2 = DB::table('users')->insertGetId([
            'name' => 'Fictional Bob Dev',
            'email' => 'bob@fictional-domain.test',
            'password' => Hash::make('secret-hash-456'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Roles
        $roleIdAdmin = DB::table('roles')->insertGetId([
            'name' => 'admin',
            'display_name' => 'Administrator',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $roleIdMember = DB::table('roles')->insertGetId([
            'name' => 'member',
            'display_name' => 'General Member',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Role User
        DB::table('role_user')->insert([
            ['role_id' => $roleIdAdmin, 'user_id' => $userId1],
            ['role_id' => $roleIdMember, 'user_id' => $userId2],
        ]);

        // 4. Permissions
        DB::table('permissions')->insert([
            ['name' => 'users.create', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'users.view', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'orders.manage', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // 5. Departments & Employees
        $deptId = DB::table('departments')->insertGetId([
            'name' => 'Engineering',
            'code' => 'ENG',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employees')->insert([
            [
                'department_id' => $deptId,
                'first_name' => 'Alice',
                'last_name' => 'Smith',
                'ssn' => '000-00-0001',
                'email' => 'alice.smith@fictional-corp.test',
                'phone_number' => '+15550199001',
                'salary' => 95000.00,
                'deleted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'department_id' => $deptId,
                'first_name' => 'Bob',
                'last_name' => 'Jones',
                'ssn' => '000-00-0002',
                'email' => 'bob.jones@fictional-corp.test',
                'phone_number' => '+15550199002',
                'salary' => 85000.00,
                'deleted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // 6. Projects & Tasks
        $projId = DB::table('projects')->insertGetId([
            'title' => 'Core Architecture Revamp',
            'description' => 'Upgrading architecture to modern modular standards.',
            'status' => 'in_progress',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('project_user')->insert([
            ['project_id' => $projId, 'user_id' => $userId1, 'role_in_project' => 'lead'],
            ['project_id' => $projId, 'user_id' => $userId2, 'role_in_project' => 'contributor'],
        ]);

        DB::table('tasks')->insert([
            [
                'project_id' => $projId,
                'assigned_to' => $userId2,
                'title' => 'Stabilize multi-engine migration suite',
                'is_completed' => true,
                'metadata' => json_encode(['priority' => 'high', 'tags' => ['database', 'testing']]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // 7. Categories & Products
        $catId = DB::table('categories')->insertGetId([
            'name' => 'Developer Tools',
            'slug' => 'developer-tools',
            'parent_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $prodId = DB::table('products')->insertGetId([
            'category_id' => $catId,
            'sku' => 'SKU-LDS-001',
            'name' => 'Database Lifecycle Toolkit Pro',
            'price' => 199.99,
            'stock_quantity' => 500,
            'attributes' => json_encode(['tier' => 'enterprise', 'license' => 'unlimited']),
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 8. Orders & Order Items
        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $userId1,
            'order_number' => 'ORD-2026-9001',
            'total_amount' => 199.99,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('order_items')->insert([
            [
                'order_id' => $orderId,
                'product_id' => $prodId,
                'quantity' => 1,
                'unit_price' => 199.99,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // 9. Settings
        DB::table('settings')->insert([
            ['key' => 'system.maintenance_mode', 'value' => 'false', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'system.max_batch_size', 'value' => '500', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // 10. Audit Logs
        DB::table('audit_logs')->insert([
            [
                'action' => 'ORDER_CREATED',
                'model_type' => 'App\\Models\\Order',
                'model_id' => $orderId,
                'payload' => json_encode(['order_number' => 'ORD-2026-9001', 'total' => 199.99]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
