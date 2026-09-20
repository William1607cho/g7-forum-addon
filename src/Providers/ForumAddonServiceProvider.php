<?php

namespace Plugins\G7\Forum\Addon\Providers;

use App\Extension\BasePluginServiceProvider;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Plugins\G7\Forum\Addon\Repositories\ActivitySortedPostRepository;

/**
 * 포럼게시판 애드온 서비스 프로바이더.
 *
 * 코어 `PluginServiceProvider` 가 `src/Providers/*ServiceProvider.php` 를 자동 발견해
 * 등록하며, 라우트(`src/routes/api.php`)·마이그레이션(`database/migrations/`)·레이아웃
 * 확장(`resources/extensions/*.json`)·훅 리스너(`plugin.php::getHookListeners()`)는
 * PluginManager 가 규약에 따라 자동으로 집어간다.
 *
 * ── PostRepositoryInterface 재바인딩 (1.2.0) ────────────────────
 * 포럼 유형 게시판의 목록을 최근 활동순으로 강제 정렬하기 위해 sirsoft-board 의
 * 게시글 Repository 를 애드온 구현으로 바꾼다. 아래 `$repositories` 는 베이스 클래스의
 * `registerRepositories()` 가 `register()` 단계에서 컨테이너에 넣는다.
 *
 * 코어 `bootstrap/providers.php` 는 `ModuleServiceProvider` → `PluginServiceProvider`
 * 순서로 등록하므로 이 바인딩이 sirsoft-board 의 기본 바인딩보다 **나중**이고,
 * 따라서 이 구현이 쓰인다. `singleton` 이 아니라 `bind` 라 먼저 해석된 인스턴스가
 * 굳어 있을 여지도 없다.
 *
 * `ActivitySortedPostRepository` 는 코어 `PostRepository` 를 상속해 forum 이 아닌
 * 게시판은 부모 구현으로 그대로 흘려보낸다 — 다른 게시판 유형의 동작은 바뀌지 않는다.
 */
class ForumAddonServiceProvider extends BasePluginServiceProvider
{
    protected string $pluginIdentifier = 'g7-forum-addon';

    /**
     * Repository 인터페이스 재바인딩.
     *
     * @var array<class-string, class-string>
     */
    protected array $repositories = [
        PostRepositoryInterface::class => ActivitySortedPostRepository::class,
    ];
}
