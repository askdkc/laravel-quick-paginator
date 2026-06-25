<?php

namespace Dkc\LaravelQuickPaginator\Tests;

use Dkc\LaravelQuickPaginator\CachedPaginationServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            CachedPaginationServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cached-pagination.ttl', 300);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->app['db']->connection()->getSchemaBuilder()->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->string('role')->nullable();
            $table->unsignedInteger('score')->default(0);
            $table->timestamps();
        });

        $this->app['db']->connection()->getSchemaBuilder()->create('posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('title');
        });
    }

    protected function seedUsers(): void
    {
        User::query()->insert([
            ['name' => 'Ada', 'active' => true, 'role' => 'admin', 'score' => 10],
            ['name' => 'Bea', 'active' => true, 'role' => 'admin', 'score' => 20],
            ['name' => 'Cid', 'active' => true, 'role' => 'member', 'score' => 30],
            ['name' => 'Dee', 'active' => false, 'role' => 'member', 'score' => 40],
        ]);

        Post::query()->insert([
            ['user_id' => 1, 'title' => 'Hello'],
            ['user_id' => 2, 'title' => 'World'],
        ]);
    }
}

class User extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}

class Post extends Model
{
    protected $table = 'posts';

    public $timestamps = false;

    protected $guarded = [];
}
