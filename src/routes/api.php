<?php

use Illuminate\Support\Facades\Route;
use Plugins\G7\Forum\Addon\Http\Controllers\AcceptedReplyController;
use Plugins\G7\Forum\Addon\Http\Controllers\PostLockController;
use Plugins\G7\Forum\Addon\Http\Controllers\PostMetaController;
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
// 인가는 컨트롤러 베이스(AdminBaseController: auth:sanctum + admin)가 전담한다 →
// 사이트 관리자만. 컨트롤러가 /meta 와 동일한 가시성 규칙 + 포럼유형을 재검증한다.
Route::post('posts/{id}/lock', [PostLockController::class, 'lock'])
    ->whereNumber('id')
    ->name('posts.lock');

Route::post('posts/{id}/unlock', [PostLockController::class, 'unlock'])
    ->whereNumber('id')
    ->name('posts.unlock');

// 리액션(추천/좋아요) 토글 — 게시글·댓글 양쪽. 1인 1리액션(같은 종류 재클릭=취소,
// 다른 종류=교체). `auth:sanctum` → 로그인 사용자만(비회원 401). 컨트롤러가 /meta 와
// 동일한 가시성 + 포럼유형을 재검증한다. 잠금 상태와 무관하게 동작.
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
