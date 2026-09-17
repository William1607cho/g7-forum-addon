<?php

namespace Plugins\G7\Forum\Addon\Tests;

use App\Http\Middleware\PermissionMiddleware;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Board\Models\Board;
use Tests\TestCase;

/**
 * g7-forum-addon 테스트 베이스 클래스
 *
 * - 데이터 정리는 DatabaseTransactions 롤백으로만 한다. 기존 행을 DELETE/TRUNCATE 하지 않는다.
 * - 애드온 라우트는 PluginManager 가 설치 상태에서만 등록하므로, 테스트는 가드·컨트롤러를
 *   컨테이너에서 직접 꺼내 호출하고 상태 코드를 확인한다.
 * - 이 플러그인은 번들(`plugins/_bundled`)이 아니므로 코어 phpunit 스위트에 자동 포함되지 않는다.
 *   실행할 때는 테스트 전용 DB 에서 경로를 직접 지정한다.
 */
abstract class PluginTestCase extends TestCase
{
    use DatabaseTransactions;

    /**
     * 마이그레이션 완료 플래그 (프로세스당 한 번만 실행)
     */
    protected static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerPluginAutoload();
        $this->runMigrationsIfNeeded();

        // 이전 테스트에서 적재된 guest 역할 캐시가 롤백 뒤에도 남지 않도록 비운다.
        PermissionMiddleware::clearGuestRoleCache();
    }

    protected function getPluginBasePath(): string
    {
        return dirname(__DIR__);
    }

    /**
     * composer.json 의 PSR-4 매핑(`src/`, `./`)과 같은 규칙으로 오토로드를 등록한다.
     */
    protected function registerPluginAutoload(): void
    {
        $base = $this->getPluginBasePath();

        spl_autoload_register(function ($class) use ($base) {
            $prefix = 'Plugins\\G7\\Forum\\Addon\\';
            if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
                return;
            }

            $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
            foreach ([$base.'/src/'.$relative.'.php', $base.'/'.lcfirst($relative).'.php', $base.'/'.$relative.'.php'] as $file) {
                if (file_exists($file) && ! class_exists($class, false)) {
                    require_once $file;

                    return;
                }
            }
        });
    }

    protected function runMigrationsIfNeeded(): void
    {
        if (static::$migrated) {
            return;
        }

        if (! Schema::hasTable('users') || ! Schema::hasTable('board_posts')) {
            $paths = ['database/migrations'];
            foreach (glob(base_path('modules/*/database/migrations'), GLOB_ONLYDIR) as $p) {
                $paths[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $p);
            }
            $this->artisan('migrate', ['--path' => $paths]);
        }

        if (! Schema::hasTable('g7_forum_addon_reactions')) {
            $this->artisan('migrate', [
                '--path' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $this->getPluginBasePath().'/database/migrations'),
                '--realpath' => false,
            ]);
        }

        static::$migrated = true;
    }

    /**
     * 테스트 게시판을 만든다. slug 는 기존 데이터와 겹치지 않게 무작위 접미사를 붙인다.
     */
    protected function createBoard(string $type = 'forum', bool $active = true): Board
    {
        $slug = 'fa-test-'.$type.'-'.substr(md5(uniqid('', true)), 0, 8);

        return Board::create([
            'slug' => $slug,
            'name' => ['ko' => '포럼 애드온 테스트', 'en' => 'Forum add-on test'],
            'type' => $type,
            'is_active' => $active,
            'secret_mode' => 'enabled',
            'blocked_keywords' => [],
        ]);
    }

    protected function createPost(Board $board, array $attributes = []): int
    {
        return DB::table('board_posts')->insertGetId(array_merge([
            'board_id' => $board->id,
            'title' => 'test',
            'content' => 'test',
            'user_id' => null,
            'author_name' => 'test',
            'password' => null,
            'ip_address' => '127.0.0.1',
            'is_notice' => false,
            'is_secret' => false,
            'status' => 'published',
            'trigger_type' => 'admin',
            'view_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    protected function createComment(Board $board, int $postId, array $attributes = []): int
    {
        return DB::table('board_comments')->insertGetId(array_merge([
            'board_id' => $board->id,
            'post_id' => $postId,
            'user_id' => null,
            'author_name' => 'test',
            'content' => 'test',
            'password' => null,
            'ip_address' => '127.0.0.1',
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    protected function role(string $identifier): Role
    {
        return Role::firstOrCreate(
            ['identifier' => $identifier],
            ['name' => ['ko' => $identifier, 'en' => $identifier]]
        );
    }

    /**
     * 역할에 게시판 권한(`sirsoft-board.{slug}.{key}`)을 부여한다.
     */
    protected function grant(string $roleIdentifier, Board $board, string $key = 'posts.read'): void
    {
        $permission = Permission::firstOrCreate(
            ['identifier' => "sirsoft-board.{$board->slug}.{$key}"],
            ['name' => ['ko' => $key, 'en' => $key], 'type' => 'user']
        );
        $this->role($roleIdentifier)->permissions()->syncWithoutDetaching([$permission->id]);

        PermissionMiddleware::clearGuestRoleCache();
    }

    /**
     * 게시판 권한 행만 만들고 아무 역할에도 부여하지 않는다(= 비공개 게시판).
     */
    protected function declarePermission(Board $board, string $key = 'posts.read'): void
    {
        Permission::firstOrCreate(
            ['identifier' => "sirsoft-board.{$board->slug}.{$key}"],
            ['name' => ['ko' => $key, 'en' => $key], 'type' => 'user']
        );
        $this->role('guest');

        PermissionMiddleware::clearGuestRoleCache();
    }

    protected function createUserWithRole(?string $roleIdentifier = null): User
    {
        $user = User::factory()->create();
        if ($roleIdentifier !== null) {
            $user->roles()->syncWithoutDetaching([$this->role($roleIdentifier)->id]);
        }

        return $user->fresh();
    }

    /**
     * 요청자를 지정한 Request 를 만들고 Auth 에도 같은 사용자를 설정한다(null = 비회원).
     */
    protected function requestAs(?User $user, array $query = [], array $body = []): Request
    {
        $request = Request::create('/', empty($body) ? 'GET' : 'POST', array_merge($query, $body));
        $request->setUserResolver(fn () => $user);

        if ($user !== null) {
            Auth::setUser($user);
        } else {
            Auth::forgetUser();
        }

        return $request;
    }

    /**
     * 콜백을 실행해 응답/예외에서 HTTP 상태 코드를 뽑는다.
     */
    protected function statusOf(callable $callback): int
    {
        try {
            $result = $callback();
        } catch (HttpResponseException $e) {
            return $e->getResponse()->getStatusCode();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return $e->getStatusCode();
        }

        if ($result instanceof \Symfony\Component\HttpFoundation\Response) {
            return $result->getStatusCode();
        }

        return 200;
    }
}
