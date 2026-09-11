<?php

namespace Plugins\G7\Forum\Addon\Providers;

use App\Extension\BasePluginServiceProvider;

/**
 * 포럼게시판 애드온 서비스 프로바이더.
 *
 * 이 빌드(0.1.0-dev)는 컨테이너 바인딩이 필요한 서비스가 없어 식별자만 지정한다.
 * 코어 `PluginServiceProvider` 가 `src/Providers/*ServiceProvider.php` 를 자동 발견해
 * 등록하며, 라우트(`src/routes/api.php`)·마이그레이션(`database/migrations/`)·레이아웃
 * 확장(`resources/extensions/*.json`)·훅 리스너(`plugin.php::getHookListeners()`)는
 * PluginManager 가 규약에 따라 자동으로 집어간다.
 */
class ForumAddonServiceProvider extends BasePluginServiceProvider
{
    protected string $pluginIdentifier = 'g7-forum-addon';
}
