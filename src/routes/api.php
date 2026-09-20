<?php

use Illuminate\Support\Facades\Route;
use Plugins\G7\Forum\Addon\Http\Controllers\AcceptedReplyController;
use Plugins\G7\Forum\Addon\Http\Controllers\ForumListMetaController;
use Plugins\G7\Forum\Addon\Http\Controllers\PostLockController;
use Plugins\G7\Forum\Addon\Http\Controllers\PostMetaController;
use Plugins\G7\Forum\Addon\Http\Controllers\PostPinController;
use Plugins\G7\Forum\Addon\Http\Controllers\ReactionController;

/*
 * g7-forum-addon 플러그인 API 라우트
 *
 * URL prefix: /api/plugins/g7-forum-addon  (PluginRouteServiceProvider 자동 적용)
 */

// 포럼 게시글 메타 — 추천수/태그/구독여부/베스트답글/잠금 등을 sirsoft-board 응답과
// 분리해 내려준다(2-call 구조). 공개 접근이지만 컨트롤러가 sirsoft-board 와 동일한
// 가시성(비밀글/블라인드/삭제/게시판 활성) 규칙을 재검증한다.
Route::get('posts/{id}/meta', [PostMetaController::class, 'show'])
    ->whereNumber('id')
    ->middleware(['optional.sanctum', 'throttle:600,1'])
    ->name('posts.meta');

// 게시글 잠금(Lock) 토글 — 잠긴 포럼 게시글은 애드온이 새 댓글 작성을 서버에서 거부한다.
// `auth:sanctum` 이 비회원을 401 로 막고, 컨트롤러가 게시판 매니저
// (`sirsoft-board.{slug}.manager`)인지 판정해 아니면 403 이다(1.3.0 — 그 전에는
// AdminBaseController 로 사이트 관리자 전용이었다). 컨트롤러가 /meta 와 동일한
// 가시성 규칙 + 포럼유형도 재검증한다.
Route::post('posts/{id}/lock', [PostLockController::class, 'lock'])
    ->whereNumber('id')
    ->middleware(['auth:sanctum', 'throttle:120,1'])
    ->name('posts.lock');

Route::post('posts/{id}/unlock', [PostLockController::class, 'unlock'])
    ->whereNumber('id')
    ->middleware(['auth:sanctum', 'throttle:120,1'])
    ->name('posts.unlock');

// 게시글 핀(고정) 토글 — 값의 원천은 코어 `board_posts.is_notice` 다(애드온 미저장).
// 권한은 잠금과 같은 기준(게시판 매니저). 컨트롤러가 판정을 통과한 요청만 코어
// PostService::updatePost() 에 `is_notice` 키 하나로 넘긴다. 멱등이다.
Route::post('posts/{id}/pin', [PostPinController::class, 'pin'])
    ->whereNumber('id')
    ->middleware(['auth:sanctum', 'throttle:120,1'])
    ->name('posts.pin');

Route::post('posts/{id}/unpin', [PostPinController::class, 'unpin'])
    ->whereNumber('id')
    ->middleware(['auth:sanctum', 'throttle:120,1'])
    ->name('posts.unpin');

// 추천(업·다운) 토글 — 게시글·댓글 양쪽. 1인 1표(같은 쪽 재클릭=취소, 반대쪽=전환).
// `auth:sanctum` → 로그인 사용자만(비회원 401). 컨트롤러가 /meta 와 동일한 가시성 +
// 포럼유형을 재검증하고, 본인 글·본인 댓글 투표는 403 으로 거부한다(1.3.0).
// 잠금 상태와 무관하게 동작.
Route::post('{targetType}/{id}/reactions', [ReactionController::class, 'toggle'])
    ->whereIn('targetType', ['posts', 'comments'])
    ->whereNumber('id')
    ->middleware(['auth:sanctum', 'throttle:120,1'])
    ->name('reactions.toggle');

// 베스트답글(채택) — 게시글 작성자 본인 + 사이트 관리자만. 게시글당 1개(accept 는 항상
// 그 댓글로 지정=교체, unaccept 는 그 댓글이 채택 상태일 때만 해제). `auth:sanctum`.
// 컨트롤러가 /meta 와 동일한 가시성 + 포럼유형 + 댓글 소속을 재검증한다. 잠금과 무관.
Route::post('posts/{postId}/comments/{commentId}/accept', [AcceptedReplyController::class, 'accept'])
    ->whereNumber('postId')->whereNumber('commentId')
    ->middleware(['auth:sanctum', 'throttle:120,1'])
    ->name('accepted-reply.accept');

Route::post('posts/{postId}/comments/{commentId}/unaccept', [AcceptedReplyController::class, 'unaccept'])
    ->whereNumber('postId')->whereNumber('commentId')
    ->middleware(['auth:sanctum', 'throttle:120,1'])
    ->name('accepted-reply.unaccept');

// 게시판 목록 "참여자"/"최근 활동" 배치 조회 — 목록 화면이 한 페이지에 보이는 게시글
// ID 들을 모아 한 번에 호출한다(N+1 방지). forum 유형이 아닌 게시판은 404. 게시글별
// 가시성(비밀글/블라인드/삭제)은 컨트롤러가 배치용으로 재검증해 통과 못한 ID 만 제외한다.
Route::get('boards/{slug}/list-meta', [ForumListMetaController::class, 'index'])
    ->middleware(['optional.sanctum', 'throttle:300,1'])
    ->name('boards.list-meta');
